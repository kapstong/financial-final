<?php
/**
 * Currency formatting helper
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function format_currency($amount, $symbol = '₱', $decimals = 2)
{
    // Normalize amount
    $amt = is_numeric($amount) ? (float)$amount : 0.0;

    // Format number with thousands separator
    $formatted = number_format($amt, $decimals, '.', ',');

    // If symbol is the unicode peso, render it before the amount
    if ($symbol === '\u20B1' || $symbol === '&#8369;' || $symbol === '₱') {
        return '₱' . $formatted;
    }

    return $symbol . $formatted;
}
