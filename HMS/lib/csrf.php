<?php

require_once __DIR__ . '/helpers.php';

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(string $token): void
{
    $current = $_SESSION['csrf_token'] ?? '';
    if (!$token || !hash_equals($current, $token)) {
        http_response_code(419);
        echo 'CSRF verification failed.';
        exit;
    }
}

