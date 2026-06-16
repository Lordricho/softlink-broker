<?php

/**
 * Helper functions for validation and sanitization
 */

function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePassword($password) {
    // At least 6 characters
    return strlen($password) >= 6;
}

function validatePhone($phone) {
    // Basic phone validation (10-15 digits)
    return preg_match('/^[0-9]{10,15}$/', preg_replace('/[^0-9]/', '', $phone));
}

function formatCurrency($amount) {
    return '₦' . number_format($amount, 2);
}

function formatDate($date) {
    return date('M d, Y h:i A', strtotime($date));
}

?>
