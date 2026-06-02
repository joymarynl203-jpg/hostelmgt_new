<?php

declare(strict_types=1);

require_once __DIR__ . '/sa_bootstrap.php';
require_once HMS_ROOT . '/lib/helpers.php';
require_once HMS_ROOT . '/db.php';

function sa_url(string $path = ''): string
{
    $base = rtrim(SA_BASE_URL, '/');
    $path = ltrim($path, '/');
    return $base . ($path !== '' ? '/' . $path : '');
}

function sa_redirect(string $path): void
{
    header('Location: ' . sa_url($path));
    exit;
}
