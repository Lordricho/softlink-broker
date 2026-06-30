/* ── Shared admin layout ─────────────────────────────── */
.admin-wrap {
    display: grid;
    grid-template-columns: 220px 1fr;
    min-height: calc(100vh - 60px);
}
.sidebar {
    background: #1a1d2e;
    padding: 24px 0;
    position: sticky;
    top: 0;
    height: calc(100vh - 60px);
    overflow-y: auto;
}
.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 20px 24px;
    border-bottom: 1px solid rgba(255,255,255,.08);
    margin-bottom: 16px;
}
.sidebar-brand span {
    font-size: 13px;
    font-weight: 700;
    color: #a78bfa;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.sidebar nav a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 20px;
    color: #94a3b8;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: background .2s, color .2s;
}
.sidebar nav a:hover,
.sidebar nav a.active {
    background: rgba(167,139,250,.12);
    color: #a78bfa;
}
.sidebar nav a .icon { font-size: 16px; width: 20px; text-align: center; }
.sidebar-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,.07);
    margin: 12px 0;
}

/* ── Main ────────────────────────────────────────────── */
.main {
    background: #f1f5f9;
    padding: 28px 32px;
    overflow-y: auto;
}
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
}
.page-header h1 { font-size: 22px; font-weight: 700; color: #1e293b; margin: 0; }
.page-header p  { color: #64748b; font-size: 13px; margin: 4px 0 0; }
.admin-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #7c3aed;
    color: white;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 20px;
}

/* ── Flash messages ──────────────────────────────────── */
.flash {
    padding: 12px 18px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 20px;
}
.flash-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.flash-error   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

/* ── Stat cards ──────────────────────────────────────── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 18px;
    margin-bottom: 28px;
}
.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px 22px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    display: flex;
    flex-direction: column;
    gap: 8px;
    border-top: 3px solid transparent;
    position: relative;
    overflow: hidden;
}
.stat-card.purple { border-top-color: #7c3aed; }
.stat-card.blue   { border-top-color: #2563eb; }
.stat-card.green  { border-top-color: #16a34a; }
.stat-card.red    { border-top-color: #dc2626; }
.stat-card.amber  { border-top-color: #d97706; }
.stat-card .label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #64748b;
}
.stat-card .value {
    font-size: 26px;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.1;
}
.stat-card .icon-wrap {
    font-size: 28px;
    opacity: .18;
    position: absolute;
    right: 18px;
    top: 18px;
}

/* ── Section cards ───────────────────────────────────── */
.section-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    margin-bottom: 24px;
    overflow: hidden;
}
.section-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 22px;
    border-bottom: 1px solid #f1f5f9;
}
.section-card-header h2 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }
.section-card-header .count { font-size: 12px; color: #94a3b8; }

/* ── Tables ──────────────────────────────────────────── */
.admin-table { width: 100%; border-collapse: collapse; }
.admin-table th {
    background: #f8fafc;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #64748b;
    padding: 10px 22px;
    text-align: left;
    border-bottom: 1px solid #f1f5f9;
}
.admin-table td {
    padding: 13px 22px;
    font-size: 13px;
    color: #334155;
    border-bottom: 1px solid #f8fafc;
    vertical-align: middle;
}
.admin-table tbody tr:last-child td { border-bottom: none; }
.admin-table tbody tr:hover td { background: #f8fafc; }
.empty-row td { text-align: center; color: #94a3b8; padding: 32px; font-size: 14px; }

/* ── Pills ───────────────────────────────────────────── */
.pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}
.pill-deposit    { background: #dcfce7; color: #15803d; }
.pill-withdrawal { background: #fee2e2; color: #b91c1c; }
.pill-trade      { background: #e0f2fe; color: #0369a1; }
.pill-fee        { background: #fef9c3; color: #a16207; }
.pill-completed  { background: #dcfce7; color: #15803d; }
.pill-pending    { background: #fef9c3; color: #a16207; }
.pill-failed     { background: #fee2e2; color: #b91c1c; }
.pill-admin      { background: #ede9fe; color: #6d28d9; }
.pill-user       { background: #f1f5f9; color: #475569; }
.pill-verified   { background: #dcfce7; color: #15803d; }
.pill-unverified { background: #fef9c3; color: #a16207; }
.pill-suspended  { background: #fee2e2; color: #b91c1c; }

/* ── Avatar ──────────────────────────────────────────── */
.avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.avatar.avatar-suspended { background: linear-gradient(135deg, #dc2626, #f87171); }
.user-cell { display: flex; align-items: center; gap: 10px; }
.user-cell-info { line-height: 1.3; }
.user-cell-name  { font-weight: 600; color: #1e293b; font-size: 13px; }
.user-cell-email { font-size: 11px; color: #94a3b8; }

/* ── Responsive ──────────────────────────────────────── */
@media (max-width: 900px) {
    .admin-wrap { grid-template-columns: 1fr; }
    .sidebar { display: none; }
    .main { padding: 20px 16px; }
}
