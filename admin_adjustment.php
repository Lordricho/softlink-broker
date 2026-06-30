<?php
/**
 * Admin Transaction Adjustment System
 * ─────────────────────────────────────────────────────────────────────────────
 * Supports:  manual_credit | manual_debit | reversal | void | correction
 * Every action writes an immutable audit record to transaction_adjustments.
 * Original transaction records are NEVER deleted or overwritten.
 */
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');

$admin_id = requireAdmin();

// ── Helpers ───────────────────────────────────────────────────────────────────
function adjRef(): string {
    return 'ADJ-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}
function txRef(): string {
    return 'TX-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}
function fail(string $msg, string $back): never {
    header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'error=' . urlencode($msg));
    exit();
}
function ok(string $msg, string $back): never {
    header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'success=' . urlencode($msg));
    exit();
}

// ═════════════════════════════════════════════════════════════════════════════
// POST — Process adjustment
// ═════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action        = trim($_POST['action']        ?? '');
    $target_uid    = (int)($_POST['target_user_id'] ?? 0);
    $orig_tx_id    = (int)($_POST['original_tx_id'] ?? 0);   // 0 = no linked tx
    $raw_amount    = trim($_POST['amount']          ?? '');
    $reason        = trim($_POST['reason']          ?? '');
    $notes         = trim($_POST['notes']           ?? '');
    $description   = trim($_POST['description']     ?? '');
    $redirect_back = trim($_POST['redirect_back']   ?? 'admin_adjustment.php');

    // ── Basic validation ──────────────────────────────────────────────────────
    $allowed_actions = ['manual_credit','manual_debit','reversal','void','correction'];
    if (!in_array($action, $allowed_actions, true)) fail('Unknown action.', $redirect_back);
    if (!$target_uid)  fail('Target user is required.',  $redirect_back);
    if ($reason === '') fail('Reason for adjustment is required.', $redirect_back);

    // Amount required for all except void
    $amount = 0.0;
    if ($action !== 'void') {
        if (!is_numeric($raw_amount) || (float)$raw_amount <= 0) {
            fail('A valid positive amount is required.', $redirect_back);
        }
        $amount = round((float)$raw_amount, 2);
    }

    // Linked tx required for reversal / void / correction
    if (in_array($action, ['reversal','void','correction'], true) && !$orig_tx_id) {
        fail('A linked transaction is required for this action.', $redirect_back);
    }

    try {
        // ── Load target user ─────────────────────────────────────────────────
        $u_stmt = $conn->prepare('SELECT id, fullname, email, balance FROM users WHERE id = :id FOR UPDATE');
        // We start the transaction first so FOR UPDATE works
        $conn->beginTransaction();
        $u_stmt->execute([':id' => $target_uid]);
        $user = $u_stmt->fetch();
        if (!$user) { $conn->rollBack(); fail('User not found.', $redirect_back); }

        // ── Load original transaction if needed ──────────────────────────────
        $orig_tx = null;
        if ($orig_tx_id) {
            $tx_stmt = $conn->prepare('SELECT * FROM transactions WHERE id = :id AND user_id = :uid');
            $tx_stmt->execute([':id' => $orig_tx_id, ':uid' => $target_uid]);
            $orig_tx = $tx_stmt->fetch();
            if (!$orig_tx) { $conn->rollBack(); fail('Original transaction not found or does not belong to this user.', $redirect_back); }
        }

        $adj_ref        = adjRef();
        $net_balance    = 0.0;  // positive = credit, negative = debit
        $new_tx_id      = null;

        // ── Execute action ────────────────────────────────────────────────────
        switch ($action) {

            // ── Manual Credit ─────────────────────────────────────────────────
            case 'manual_credit':
                $net_balance = $amount;
                $new_tx_stmt = $conn->prepare("
                    INSERT INTO transactions (user_id, type, amount, description, status, reference, created_at)
                    VALUES (:uid, 'adjustment', :amount, :desc, 'completed', :ref, CURRENT_TIMESTAMP)
                    RETURNING id
                ");
                $new_tx_stmt->execute([
                    ':uid'    => $target_uid,
                    ':amount' => $amount,
                    ':desc'   => $description ?: 'Manual credit adjustment by admin',
                    ':ref'    => txRef(),
                ]);
                $new_tx_id = $new_tx_stmt->fetchColumn();
                break;

            // ── Manual Debit ──────────────────────────────────────────────────
            case 'manual_debit':
                if ((float)$user['balance'] < $amount) {
                    $conn->rollBack();
                    fail('Insufficient balance. User balance is ' . formatCurrency($user['balance']) . '.', $redirect_back);
                }
                $net_balance = -$amount;
                $new_tx_stmt = $conn->prepare("
                    INSERT INTO transactions (user_id, type, amount, description, status, reference, created_at)
                    VALUES (:uid, 'adjustment', :amount, :desc, 'completed', :ref, CURRENT_TIMESTAMP)
                    RETURNING id
                ");
                $new_tx_stmt->execute([
                    ':uid'    => $target_uid,
                    ':amount' => $amount,
                    ':desc'   => $description ?: 'Manual debit adjustment by admin',
                    ':ref'    => txRef(),
                ]);
                $new_tx_id = $new_tx_stmt->fetchColumn();
                break;

            // ── Reversal ──────────────────────────────────────────────────────
            case 'reversal':
                if ($orig_tx['status'] === 'voided') {
                    $conn->rollBack();
                    fail('Cannot reverse a voided transaction.', $redirect_back);
                }
                // Credits that were applied to balance get reversed (debit), and vice-versa
                $credit_types = ['deposit','adjustment'];
                if (in_array($orig_tx['type'], $credit_types, true)) {
                    // Original was a credit — reverse = debit
                    if ((float)$user['balance'] < (float)$orig_tx['amount']) {
                        $conn->rollBack();
                        fail('Insufficient balance to reverse this deposit. User balance is ' . formatCurrency($user['balance']) . '.', $redirect_back);
                    }
                    $net_balance = -(float)$orig_tx['amount'];
                } else {
                    // Original was a debit (withdrawal/fee) — reverse = credit
                    $net_balance = (float)$orig_tx['amount'];
                }
                $amount = abs($net_balance);
                $new_tx_stmt = $conn->prepare("
                    INSERT INTO transactions (user_id, type, amount, description, status, reference, created_at)
                    VALUES (:uid, 'reversal', :amount, :desc, 'completed', :ref, CURRENT_TIMESTAMP)
                    RETURNING id
                ");
                $new_tx_stmt->execute([
                    ':uid'    => $target_uid,
                    ':amount' => $amount,
                    ':desc'   => 'Reversal of transaction #' . $orig_tx_id . ($description ? ': ' . $description : ''),
                    ':ref'    => txRef(),
                ]);
                $new_tx_id = $new_tx_stmt->fetchColumn();
                break;

            // ── Void ──────────────────────────────────────────────────────────
            case 'void':
                if ($orig_tx['status'] === 'voided') {
                    $conn->rollBack();
                    fail('Transaction is already voided.', $redirect_back);
                }
                // Only completed transactions have affected the balance — reverse them
                if ($orig_tx['status'] === 'completed') {
                    $credit_types = ['deposit','adjustment'];
                    if (in_array($orig_tx['type'], $credit_types, true)) {
                        if ((float)$user['balance'] < (float)$orig_tx['amount']) {
                            $conn->rollBack();
                            fail('Insufficient balance to void this credit transaction. User balance is ' . formatCurrency($user['balance']) . '.', $redirect_back);
                        }
                        $net_balance = -(float)$orig_tx['amount'];
                    } else {
                        $net_balance = (float)$orig_tx['amount'];
                    }
                }
                $amount = abs($net_balance);
                // Mark original as voided — no new transaction created for void
                $conn->prepare("UPDATE transactions SET status = 'voided' WHERE id = :id")
                     ->execute([':id' => $orig_tx_id]);
                break;

            // ── Correction ────────────────────────────────────────────────────
            case 'correction':
                if ($orig_tx['status'] === 'voided') {
                    $conn->rollBack();
                    fail('Cannot correct a voided transaction.', $redirect_back);
                }
                if ($orig_tx['status'] !== 'completed') {
                    $conn->rollBack();
                    fail('Only completed transactions can be corrected.', $redirect_back);
                }
                // $amount = the NEW intended amount; difference = new - original
                $diff = round($amount - (float)$orig_tx['amount'], 2);
                if ($diff == 0) {
                    $conn->rollBack();
                    fail('Correction amount is the same as the original — no change needed.', $redirect_back);
                }
                $credit_types = ['deposit','adjustment'];
                $is_credit = in_array($orig_tx['type'], $credit_types, true);
                // For a credit type: positive diff = extra credit; negative = extra debit
                // For a debit type:  positive diff = extra debit;   negative = extra credit
                if ($is_credit) {
                    $net_balance = $diff;
                } else {
                    $net_balance = -$diff;
                }
                if ($net_balance < 0 && (float)$user['balance'] < abs($net_balance)) {
                    $conn->rollBack();
                    fail('Insufficient balance for this correction. User balance is ' . formatCurrency($user['balance']) . '.', $redirect_back);
                }
                $corr_amount = abs($diff);
                $new_tx_stmt = $conn->prepare("
                    INSERT INTO transactions (user_id, type, amount, description, status, reference, created_at)
                    VALUES (:uid, 'correction', :amount, :desc, 'completed', :ref, CURRENT_TIMESTAMP)
                    RETURNING id
                ");
                $new_tx_stmt->execute([
                    ':uid'    => $target_uid,
                    ':amount' => $corr_amount,
                    ':desc'   => 'Correction of transaction #' . $orig_tx_id . ' from ' . formatCurrency($orig_tx['amount']) . ' to ' . formatCurrency($amount) . ($description ? ': ' . $description : ''),
                    ':ref'    => txRef(),
                ]);
                $new_tx_id = $new_tx_stmt->fetchColumn();
                $amount    = $corr_amount;
                break;
        }

        // ── Apply balance change ──────────────────────────────────────────────
        if ($net_balance != 0) {
            $conn->prepare("UPDATE users SET balance = GREATEST(0, balance + :delta) WHERE id = :uid")
                 ->execute([':delta' => $net_balance, ':uid' => $target_uid]);
        }

        // ── Write audit log ───────────────────────────────────────────────────
        $audit = $conn->prepare("
            INSERT INTO transaction_adjustments
                (admin_id, target_user_id, original_tx_id, action, adjustment_amount,
                 net_balance_change, reason, reference, notes)
            VALUES
                (:admin, :uid, :orig, :action, :amt, :net, :reason, :ref, :notes)
        ");
        $audit->execute([
            ':admin'  => $admin_id,
            ':uid'    => $target_uid,
            ':orig'   => $orig_tx_id ?: null,
            ':action' => $action,
            ':amt'    => $amount,
            ':net'    => $net_balance,
            ':reason' => $reason,
            ':ref'    => $adj_ref,
            ':notes'  => $notes ?: null,
        ]);

        $conn->commit();

        // Notify admins
        include_once('config/notify.php');
        $adj_pri = in_array($action, ['manual_debit','void','reversal'], true) ? 'high' : 'medium';
        $adj_titles = [
            'manual_credit' => 'Manual Credit Applied',
            'manual_debit'  => 'Manual Debit Applied',
            'reversal'      => 'Transaction Reversal',
            'void'          => 'Transaction Voided',
            'correction'    => 'Transaction Correction',
        ];
        createNotification($conn, [
            'event_type'  => 'adjustment_' . $action,
            'category'    => 'transactions',
            'priority'    => $adj_pri,
            'title'       => $adj_titles[$action] ?? 'Adjustment Applied',
            'description' => ucfirst(str_replace('_',' ',$action)) . ' for user #' . $target_uid . ': ' . $reason . ' (Ref: ' . $adj_ref . ')',
            'user_id'     => $target_uid,
            'reference'   => $adj_ref,
        ]);

        $label_map = [
            'manual_credit' => 'Manual credit applied',
            'manual_debit'  => 'Manual debit applied',
            'reversal'      => 'Reversal transaction created',
            'void'          => 'Transaction voided',
            'correction'    => 'Correction transaction created',
        ];
        $msg = ($label_map[$action] ?? 'Adjustment applied') . '. Audit ref: ' . $adj_ref;
        ok($msg, $redirect_back);

    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('Adjustment error: ' . $e->getMessage());
        fail('Database error during adjustment. Please try again.', $redirect_back);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// GET — Render page
// ═════════════════════════════════════════════════════════════════════════════
$flash      = $_GET['success'] ?? '';
$flash_type = 'success';
if (empty($flash)) { $flash = $_GET['error'] ?? ''; $flash_type = 'error'; }

$mode_tx_id  = (int)($_GET['tx_id']   ?? 0);   // adjusting a specific transaction
$mode_uid    = (int)($_GET['user_id'] ?? 0);   // manual adj for a specific user

$target_tx   = null;
$target_user = null;

// History filter / pagination
$hist_page     = max(1, (int)($_GET['hpage'] ?? 1));
$hist_per      = 20;
$hist_offset   = ($hist_page - 1) * $hist_per;
$hist_filter_action = trim($_GET['haction'] ?? '');

try {
    $me = $conn->prepare('SELECT fullname FROM users WHERE id = :id');
    $me->execute([':id' => $admin_id]);
    $admin = $me->fetch();

    if ($mode_tx_id) {
        $tx_s = $conn->prepare(
            'SELECT t.*, u.id AS uid, u.fullname, u.email, u.balance
             FROM transactions t JOIN users u ON u.id = t.user_id
             WHERE t.id = :id'
        );
        $tx_s->execute([':id' => $mode_tx_id]);
        $target_tx = $tx_s->fetch();
        if ($target_tx) {
            $mode_uid = (int)$target_tx['uid'];
        }
    }

    if ($mode_uid && !$target_user) {
        $u_s = $conn->prepare('SELECT id, fullname, email, balance FROM users WHERE id = :id');
        $u_s->execute([':id' => $mode_uid]);
        $target_user = $u_s->fetch();
    }

    // Adjustment history
    $hwhere  = $hist_filter_action ? "WHERE a.action = :faction" : "";
    $hparams = $hist_filter_action ? [':faction' => $hist_filter_action] : [];

    $hcnt = $conn->prepare("SELECT COUNT(*) FROM transaction_adjustments a {$hwhere}");
    $hcnt->execute($hparams);
    $hist_total = (int)$hcnt->fetchColumn();
    $hist_pages = max(1, ceil($hist_total / $hist_per));

    $hist_stmt = $conn->prepare("
        SELECT a.*,
               adm.fullname AS admin_name, adm.email AS admin_email,
               u.fullname   AS user_name,  u.email   AS user_email
        FROM transaction_adjustments a
        JOIN users adm ON adm.id = a.admin_id
        JOIN users u   ON u.id   = a.target_user_id
        {$hwhere}
        ORDER BY a.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($hparams as $k => $v) $hist_stmt->bindValue($k, $v);
    $hist_stmt->bindValue(':lim', $hist_per,    PDO::PARAM_INT);
    $hist_stmt->bindValue(':off', $hist_offset, PDO::PARAM_INT);
    $hist_stmt->execute();
    $history = $hist_stmt->fetchAll();

    // All users for manual adj user picker
    $all_users = $conn->query("SELECT id, fullname, email FROM users ORDER BY fullname ASC")->fetchAll();

} catch (PDOException $e) {
    error_log('Admin adjustment page error: ' . $e->getMessage());
    die('Error loading adjustment page. Please try again.');
}

$self_url = 'admin_adjustment.php' . ($mode_tx_id ? '?tx_id='.$mode_tx_id : ($mode_uid ? '?user_id='.$mode_uid : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Adjustments — Softlink Broker Admin</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        <?php include('config/admin_styles.php'); ?>

        /* ── Adjustment form card ─────────────────────── */
        .adj-card {
            background: white; border-radius: 14px;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            overflow: hidden; margin-bottom: 24px;
        }
        .adj-card-header {
            background: linear-gradient(135deg,#1a1d2e 0%,#2d2f4a 100%);
            padding: 20px 26px; display: flex; align-items: center; gap: 14px;
        }
        .adj-card-header h2  { color: white; font-size: 16px; margin: 0; }
        .adj-card-header p   { color: #94a3b8; font-size: 12px; margin: 3px 0 0; }
        .adj-card-body       { padding: 26px; }

        /* ── Tx detail strip ─────────────────────────── */
        .tx-detail-strip {
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 10px; padding: 16px 20px;
            display: grid; grid-template-columns: repeat(auto-fit, minmax(140px,1fr));
            gap: 14px; margin-bottom: 24px;
        }
        .tx-detail-item .tdi-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing:.06em; color:#94a3b8; }
        .tx-detail-item .tdi-value { font-size: 14px; font-weight: 700; color: #1e293b; margin-top: 2px; }

        /* ── Form elements ───────────────────────────── */
        .form-grid { display: grid; gap: 18px; }
        .form-grid-2 { grid-template-columns: 1fr 1fr; }
        @media(max-width:640px){ .form-grid-2{ grid-template-columns:1fr; } }

        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: #64748b;
        }
        .form-label .required { color: #dc2626; }
        .form-control {
            padding: 10px 14px; border: 1px solid #e2e8f0;
            border-radius: 8px; font-size: 13px; color: #1e293b;
            background: white; width: 100%; box-sizing: border-box;
        }
        .form-control:focus { outline: none; border-color: #a78bfa; box-shadow: 0 0 0 3px rgba(167,139,250,.15); }
        textarea.form-control { resize: vertical; min-height: 80px; font-family: inherit; }
        .form-hint { font-size: 11px; color: #94a3b8; margin-top: 3px; }

        /* ── Action tab selector ─────────────────────── */
        .action-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 22px; }
        .action-tab {
            padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
            cursor: pointer; border: 2px solid transparent; background: #f1f5f9; color: #475569;
            transition: all .15s;
        }
        .action-tab:hover { background: #e2e8f0; }
        .action-tab.selected { border-color: #7c3aed; background: #ede9fe; color: #6d28d9; }
        .action-tab input[type=radio] { display:none; }

        /* ── Submit button ───────────────────────────── */
        .btn-adj-submit {
            padding: 11px 28px; background: #7c3aed; color: white;
            border: none; border-radius: 10px; font-size: 14px;
            font-weight: 700; cursor: pointer; margin-top: 6px;
        }
        .btn-adj-submit:hover { background: #6d28d9; }
        .btn-adj-submit.red  { background: #dc2626; }
        .btn-adj-submit.red:hover { background: #b91c1c; }

        /* ── Warning box ─────────────────────────────── */
        .warn-box {
            background: #fffbeb; border: 1px solid #fde68a;
            border-radius: 8px; padding: 12px 16px;
            font-size: 12px; color: #92400e; margin-bottom: 18px;
            display: flex; gap: 8px; align-items: flex-start;
        }

        /* ── Audit history net badge ─────────────────── */
        .net-positive { color: #16a34a; font-weight: 700; }
        .net-negative { color: #dc2626; font-weight: 700; }
        .net-zero     { color: #94a3b8; font-weight: 700; }

        /* ── History filter ──────────────────────────── */
        .hist-filter {
            display: flex; gap: 8px; padding: 14px 22px;
            border-bottom: 1px solid #f1f5f9; flex-wrap: wrap;
        }
        .hf-link {
            padding: 4px 14px; border-radius: 20px; font-size: 12px;
            font-weight: 600; text-decoration: none;
            background: #f1f5f9; color: #475569;
        }
        .hf-link:hover { background: #e2e8f0; }
        .hf-link.active { background: #7c3aed; color: white; }

        /* ── Pagination ──────────────────────────────── */
        .pagination {
            display: flex; gap: 6px; padding: 16px;
            justify-content: center; flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 6px 13px; border-radius: 7px; font-size: 13px;
            font-weight: 600; text-decoration: none;
        }
        .pagination a { background: #f1f5f9; color: #475569; }
        .pagination a:hover { background: #e2e8f0; }
        .pagination .pg-cur { background: #7c3aed; color: white; }

        /* ── User quick info ─────────────────────────── */
        .user-quick {
            background: #f0fdf4; border: 1px solid #bbf7d0;
            border-radius: 10px; padding: 14px 18px;
            display: flex; align-items: center; gap: 14px;
            margin-bottom: 22px;
        }
        .user-quick .uq-bal { font-size: 20px; font-weight: 800; color: #15803d; }
        .user-quick .uq-name { font-weight: 700; color: #1e293b; font-size: 14px; }
        .user-quick .uq-email { font-size: 12px; color: #64748b; }
    </style>
</head>
<body>
<header class="navbar">
    <h2>🏦 Softlink Broker</h2>
    <nav>
        <span style="color:rgba(255,255,255,.75);font-size:14px;">👤 <?php echo htmlspecialchars($admin['fullname']); ?> &nbsp;·&nbsp;</span>
        <a href="dashboard.php">User Dashboard</a>
        <a href="config/logout.php">Logout</a>
    </nav>
</header>

<div class="admin-wrap">
    <aside class="sidebar">
        <div class="sidebar-brand"><span>⚙️ &nbsp;Admin Panel</span></div>
        <nav>
            <a href="admin_dashboard.php"><span class="icon">📊</span> Overview</a>
            <a href="admin_users.php"><span class="icon">👥</span> User Management</a>
            <a href="admin_transactions.php"><span class="icon">🔄</span> Transactions</a>
            <a href="admin_adjustment.php" class="active"><span class="icon">⚙️</span> Adjustments</a>
            <a href="admin_notifications.php"><span class="icon">🔔</span> Notifications <span id="sb-notif-badge" style="display:none;background:#dc2626;color:white;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:800;margin-left:4px;vertical-align:middle;"></span></a>
            <hr class="sidebar-divider">
            <a href="dashboard.php"><span class="icon">👤</span> My Dashboard</a>
            <a href="deposit.php"><span class="icon">💰</span> Deposit</a>
            <a href="withdraw.php"><span class="icon">🏦</span> Withdraw</a>
            <hr class="sidebar-divider">
            <a href="config/logout.php"><span class="icon">🚪</span> Logout</a>
        </nav>
    </aside>

    <main class="main">
        <!-- Flash -->
        <?php if ($flash): ?>
        <div class="flash flash-<?php echo $flash_type; ?>"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>⚙️ Transaction Adjustments</h1>
                <p>
                    <?php if ($mode_tx_id && $target_tx): ?>
                        Adjusting transaction #<?php echo $mode_tx_id; ?> &nbsp;·&nbsp;
                    <?php endif; ?>
                    All adjustments are audited and non-destructive
                </p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <a href="admin_transactions.php" style="font-size:13px;color:#7c3aed;text-decoration:none;">← Back to Transactions</a>
                <span class="admin-badge">🔐 Administrator</span>
            </div>
        </div>

        <!-- ── Adjustment form ── -->
        <div class="adj-card">
            <div class="adj-card-header">
                <div style="font-size:24px;">
                    <?php echo $mode_tx_id ? '🔧' : '✏️'; ?>
                </div>
                <div>
                    <h2><?php echo $mode_tx_id ? 'Adjust Transaction #'.$mode_tx_id : 'Create Manual Adjustment'; ?></h2>
                    <p><?php echo $mode_tx_id
                        ? 'Reverse, void, or correct an existing transaction'
                        : 'Create a manual credit or debit for a user account'; ?></p>
                </div>
            </div>
            <div class="adj-card-body">

                <!-- If we have a linked transaction: show its details -->
                <?php if ($target_tx): ?>
                <div class="tx-detail-strip">
                    <div class="tx-detail-item">
                        <div class="tdi-label">Transaction ID</div>
                        <div class="tdi-value">#<?php echo $target_tx['id']; ?></div>
                    </div>
                    <div class="tx-detail-item">
                        <div class="tdi-label">User</div>
                        <div class="tdi-value">
                            <a href="admin_user_profile.php?id=<?php echo $target_tx['uid']; ?>"
                               style="color:#7c3aed;text-decoration:none;">
                                <?php echo htmlspecialchars($target_tx['fullname']); ?>
                            </a>
                        </div>
                    </div>
                    <div class="tx-detail-item">
                        <div class="tdi-label">Type</div>
                        <div class="tdi-value"><span class="pill pill-<?php echo $target_tx['type']; ?>"><?php echo ucfirst($target_tx['type']); ?></span></div>
                    </div>
                    <div class="tx-detail-item">
                        <div class="tdi-label">Amount</div>
                        <div class="tdi-value"><?php echo formatCurrency($target_tx['amount']); ?></div>
                    </div>
                    <div class="tx-detail-item">
                        <div class="tdi-label">Status</div>
                        <div class="tdi-value"><span class="pill pill-<?php echo $target_tx['status']; ?>"><?php echo ucfirst($target_tx['status']); ?></span></div>
                    </div>
                    <div class="tx-detail-item">
                        <div class="tdi-label">Date</div>
                        <div class="tdi-value" style="font-size:12px;"><?php echo formatDate($target_tx['created_at']); ?></div>
                    </div>
                    <div class="tx-detail-item">
                        <div class="tdi-label">User Balance</div>
                        <div class="tdi-value" style="color:#16a34a;"><?php echo formatCurrency($target_tx['balance']); ?></div>
                    </div>
                    <?php if ($target_tx['description']): ?>
                    <div class="tx-detail-item" style="grid-column:1/-1;">
                        <div class="tdi-label">Description</div>
                        <div class="tdi-value" style="font-weight:400;color:#475569;"><?php echo htmlspecialchars($target_tx['description']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- User quick card (when no tx_id but uid known) -->
                <?php if ($target_user && !$target_tx): ?>
                <div class="user-quick">
                    <div class="avatar" style="width:44px;height:44px;font-size:16px;">
                        <?php echo strtoupper(mb_substr($target_user['fullname'],0,1)); ?>
                    </div>
                    <div style="flex:1;">
                        <div class="uq-name"><?php echo htmlspecialchars($target_user['fullname']); ?></div>
                        <div class="uq-email"><?php echo htmlspecialchars($target_user['email']); ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;">Current Balance</div>
                        <div class="uq-bal"><?php echo formatCurrency($target_user['balance']); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Warn if tx is already voided -->
                <?php if ($target_tx && $target_tx['status'] === 'voided'): ?>
                <div class="warn-box">⚠️ This transaction is already voided. Only "Manual Credit" or "Manual Debit" actions are available for this user.</div>
                <?php endif; ?>

                <form method="POST" action="admin_adjustment.php"
                      onsubmit="return confirmAdjustment(this)">
                    <input type="hidden" name="target_user_id"  value="<?php echo $mode_uid ?: ''; ?>">
                    <input type="hidden" name="original_tx_id"  value="<?php echo $mode_tx_id ?: ''; ?>">
                    <input type="hidden" name="redirect_back"   value="<?php echo htmlspecialchars($self_url); ?>">

                    <!-- Action selector -->
                    <div style="margin-bottom:20px;">
                        <div class="form-label" style="margin-bottom:10px;">
                            Action <span class="required">*</span>
                        </div>
                        <div class="action-tabs" id="actionTabs">
                            <?php
                            $tx_actions  = ['reversal'=>['🔁 Reversal','Reverses the original transaction and adjusts balance'],
                                            'void'    =>['🚫 Void','Cancels the transaction and reverses balance (if completed)'],
                                            'correction'=>['✏️ Correction','Adjusts to the correct amount; applies the difference']];
                            $man_actions = ['manual_credit'=>['⬆ Manual Credit','Adds funds to user account'],
                                            'manual_debit' =>['⬇ Manual Debit', 'Removes funds from user account']];

                            $show = $mode_tx_id ? array_merge($tx_actions, $man_actions) : $man_actions;
                            $first = true;
                            foreach ($show as $val => [$label, $desc]):
                                $disabled = ($target_tx && $target_tx['status']==='voided' && in_array($val,['reversal','void','correction'],true)) ? 'disabled' : '';
                            ?>
                            <label class="action-tab <?php echo $first?'selected':''; ?> <?php echo $disabled?'opacity-50':''; ?>"
                                   style="<?php echo $disabled?'opacity:.4;cursor:not-allowed;':''; ?>">
                                <input type="radio" name="action" value="<?php echo $val; ?>"
                                       <?php echo $first?'checked':''; ?> <?php echo $disabled; ?>
                                       onchange="updateActionUI(this)">
                                <?php echo $label; ?>
                                <span style="display:block;font-size:10px;font-weight:400;color:#94a3b8;margin-top:2px;"><?php echo $desc; ?></span>
                            </label>
                            <?php $first=false; endforeach; ?>
                        </div>
                    </div>

                    <div class="form-grid">
                        <!-- User selector (only if no user pre-selected) -->
                        <?php if (!$mode_uid): ?>
                        <div class="form-group">
                            <label class="form-label">User Account <span class="required">*</span></label>
                            <select name="target_user_id" class="form-control" required id="userSelect">
                                <option value="">— Select a user —</option>
                                <?php foreach ($all_users as $u): ?>
                                <option value="<?php echo $u['id']; ?>">
                                    <?php echo htmlspecialchars($u['fullname']); ?> (<?php echo htmlspecialchars($u['email']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- Amount (hidden for void) -->
                        <div class="form-group" id="amountRow">
                            <label class="form-label" id="amountLabel">Amount (₦) <span class="required">*</span></label>
                            <input type="number" name="amount" id="amountInput" step="0.01" min="0.01"
                                   class="form-control" placeholder="0.00">
                            <div class="form-hint" id="amountHint">
                                <?php if ($target_tx && $target_tx['status'] !== 'voided'): ?>
                                Original amount: <?php echo formatCurrency($target_tx['amount']); ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="form-group">
                            <label class="form-label">Description <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                            <input type="text" name="description" class="form-control"
                                   placeholder="Brief description for the transaction record">
                        </div>
                    </div>

                    <div class="form-grid" style="margin-top:18px;">
                        <!-- Reason — always required -->
                        <div class="form-group">
                            <label class="form-label">Reason for Adjustment <span class="required">*</span></label>
                            <textarea name="reason" class="form-control" required
                                      placeholder="Explain why this adjustment is being made. This is recorded in the audit log and cannot be changed."></textarea>
                            <div class="form-hint">Required. Saved permanently in the audit log.</div>
                        </div>

                        <!-- Internal notes -->
                        <div class="form-group">
                            <label class="form-label">Internal Notes <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                            <textarea name="notes" class="form-control"
                                      placeholder="Additional context for admins only (not visible to user)"></textarea>
                        </div>
                    </div>

                    <div style="margin-top:22px;padding-top:18px;border-top:1px solid #f1f5f9;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                        <button type="submit" class="btn-adj-submit" id="submitBtn">
                            Apply Adjustment
                        </button>
                        <a href="admin_transactions.php" style="font-size:13px;color:#64748b;text-decoration:none;">Cancel</a>
                        <span style="font-size:11px;color:#94a3b8;margin-left:auto;">
                            ⚠️ All adjustments are final and fully audited. Original records are never modified.
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Adjustment Audit History ── -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>📋 Adjustment Audit Log</h2>
                <span class="count"><?php echo number_format($hist_total); ?> record<?php echo $hist_total!==1?'s':''; ?></span>
            </div>

            <!-- Filter by action -->
            <div class="hist-filter">
                <a href="admin_adjustment.php<?php echo $mode_tx_id?'?tx_id='.$mode_tx_id:($mode_uid?'?user_id='.$mode_uid:''); ?>"
                   class="hf-link <?php echo $hist_filter_action===''?'active':''; ?>">All</a>
                <?php foreach (['manual_credit','manual_debit','reversal','void','correction'] as $ha): ?>
                <a href="?<?php echo http_build_query(array_filter(['tx_id'=>$mode_tx_id,'user_id'=>$mode_uid,'haction'=>$ha])); ?>"
                   class="hf-link <?php echo $hist_filter_action===$ha?'active':''; ?>">
                    <?php echo ucfirst(str_replace('_',' ',$ha)); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($history)): ?>
            <p style="text-align:center;color:#94a3b8;padding:32px;">No adjustment records found.</p>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Admin</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Amount</th>
                        <th>Balance Δ</th>
                        <th>Orig TX</th>
                        <th>Reason</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $h):
                    $net = (float)$h['net_balance_change'];
                    $netClass = $net > 0 ? 'net-positive' : ($net < 0 ? 'net-negative' : 'net-zero');
                    $netLabel = ($net > 0 ? '+' : '') . formatCurrency($net);
                    $actionColors = [
                        'manual_credit' => 'pill-deposit',
                        'manual_debit'  => 'pill-withdrawal',
                        'reversal'      => 'pill-trade',
                        'void'          => 'pill-suspended',
                        'correction'    => 'pill-fee',
                    ];
                    $ac = $actionColors[$h['action']] ?? 'pill-user';
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:11px;color:#7c3aed;"><?php echo htmlspecialchars($h['reference']); ?></td>
                    <td>
                        <div style="font-weight:600;font-size:12px;"><?php echo htmlspecialchars($h['admin_name']); ?></div>
                        <div style="font-size:11px;color:#94a3b8;">#<?php echo $h['admin_id']; ?></div>
                    </td>
                    <td>
                        <a href="admin_user_profile.php?id=<?php echo $h['target_user_id']; ?>"
                           style="font-weight:600;font-size:12px;color:#7c3aed;text-decoration:none;">
                            <?php echo htmlspecialchars($h['user_name']); ?>
                        </a>
                        <div style="font-size:11px;color:#94a3b8;"><?php echo htmlspecialchars($h['user_email']); ?></div>
                    </td>
                    <td><span class="pill <?php echo $ac; ?>"><?php echo ucfirst(str_replace('_',' ',$h['action'])); ?></span></td>
                    <td style="font-weight:700;"><?php echo formatCurrency($h['adjustment_amount']); ?></td>
                    <td class="<?php echo $netClass; ?>"><?php echo $netLabel; ?></td>
                    <td style="font-size:12px;color:#64748b;">
                        <?php if ($h['original_tx_id']): ?>
                            <a href="admin_adjustment.php?tx_id=<?php echo $h['original_tx_id']; ?>"
                               style="color:#7c3aed;text-decoration:none;">#<?php echo $h['original_tx_id']; ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="max-width:200px;font-size:12px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?php echo htmlspecialchars($h['reason']); ?>">
                        <?php echo htmlspecialchars(mb_substr($h['reason'],0,60)); ?><?php echo mb_strlen($h['reason'])>60?'…':''; ?>
                    </td>
                    <td style="font-size:11px;color:#94a3b8;white-space:nowrap;"><?php echo formatDate($h['created_at']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($hist_pages > 1): ?>
            <div class="pagination">
                <?php if ($hist_page > 1): ?>
                    <a href="?<?php echo http_build_query(array_filter(['tx_id'=>$mode_tx_id,'user_id'=>$mode_uid,'haction'=>$hist_filter_action,'hpage'=>$hist_page-1])); ?>">← Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1,$hist_page-2); $p <= min($hist_pages,$hist_page+2); $p++): ?>
                    <?php if ($p===$hist_page): ?>
                        <span class="pg-cur"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="?<?php echo http_build_query(array_filter(['tx_id'=>$mode_tx_id,'user_id'=>$mode_uid,'haction'=>$hist_filter_action,'hpage'=>$p])); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($hist_page < $hist_pages): ?>
                    <a href="?<?php echo http_build_query(array_filter(['tx_id'=>$mode_tx_id,'user_id'=>$mode_uid,'haction'=>$hist_filter_action,'hpage'=>$hist_page+1])); ?>">Next →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
// ── Action UI updates ────────────────────────────────────────────────────────
function updateActionUI(radio) {
    const action = radio.value;
    // Highlight selected tab
    document.querySelectorAll('.action-tab').forEach(t => t.classList.remove('selected'));
    radio.closest('.action-tab').classList.add('selected');

    const amountRow   = document.getElementById('amountRow');
    const amountLabel = document.getElementById('amountLabel');
    const amountInput = document.getElementById('amountInput');
    const amountHint  = document.getElementById('amountHint');
    const submitBtn   = document.getElementById('submitBtn');

    if (action === 'void') {
        amountRow.style.display = 'none';
        amountInput.removeAttribute('required');
        submitBtn.textContent = '🚫 Void Transaction';
        submitBtn.className   = 'btn-adj-submit red';
    } else if (action === 'reversal') {
        amountRow.style.display = 'none';
        amountInput.removeAttribute('required');
        submitBtn.textContent = '🔁 Create Reversal';
        submitBtn.className   = 'btn-adj-submit';
    } else if (action === 'correction') {
        amountRow.style.display = '';
        amountInput.setAttribute('required', 'required');
        amountLabel.innerHTML = 'Corrected Amount (₦) <span class="required">*</span>';
        submitBtn.textContent = '✏️ Apply Correction';
        submitBtn.className   = 'btn-adj-submit';
    } else if (action === 'manual_credit') {
        amountRow.style.display = '';
        amountInput.setAttribute('required', 'required');
        amountLabel.innerHTML = 'Credit Amount (₦) <span class="required">*</span>';
        submitBtn.textContent = '⬆ Apply Credit';
        submitBtn.className   = 'btn-adj-submit';
    } else if (action === 'manual_debit') {
        amountRow.style.display = '';
        amountInput.setAttribute('required', 'required');
        amountLabel.innerHTML = 'Debit Amount (₦) <span class="required">*</span>';
        submitBtn.textContent = '⬇ Apply Debit';
        submitBtn.className   = 'btn-adj-submit red';
    }
}

// Trigger once on load to set initial state
(function() {
    const first = document.querySelector('input[name="action"]:checked');
    if (first) updateActionUI(first);
})();

// ── Confirm before submit ────────────────────────────────────────────────────
function confirmAdjustment(form) {
    const action  = form.querySelector('input[name="action"]:checked');
    const reason  = form.querySelector('textarea[name="reason"]').value.trim();
    const amount  = form.querySelector('input[name="amount"]')?.value;
    if (!action) { alert('Please select an action.'); return false; }
    if (!reason) { alert('Reason for adjustment is required.'); return false; }

    const labels = {
        manual_credit: 'CREDIT funds to',
        manual_debit:  'DEBIT funds from',
        reversal:      'REVERSE transaction for',
        void:          'VOID this transaction for',
        correction:    'CORRECT this transaction for',
    };
    const lbl = labels[action.value] || action.value;
    const amtNote = amount && action.value !== 'void' && action.value !== 'reversal'
        ? '\nAmount: ₦' + parseFloat(amount).toLocaleString('en-NG', {minimumFractionDigits:2}) : '';

    return confirm(
        'Confirm adjustment:\n\n' +
        'Action: ' + lbl + ' this user' + amtNote + '\n' +
        'Reason: ' + reason + '\n\n' +
        'This is permanent and recorded in the audit log.'
    );
}
</script>
<script>
(function pollBadge(){
    fetch('api/notifications_count.php')
        .then(r=>r.json())
        .then(d=>{
            var b=document.getElementById('sb-notif-badge');
            if(!b)return;
            if(d.unread>0){b.textContent=d.unread>99?'99+':d.unread;b.style.display='inline';}
            else{b.style.display='none';}
        }).catch(()=>{});
    setTimeout(pollBadge,30000);
})();
</script>
</body>
</html>
