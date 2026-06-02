<?php

require_once __DIR__ . '/config.php';

function hms_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . HMS_DB_HOST . ';dbname=' . HMS_DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, HMS_DB_USER, HMS_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo 'Database connection failed. Please check configuration.';
        exit;
    }

    return $pdo;
}

