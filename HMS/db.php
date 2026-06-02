<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db_dialect.php';

/**
 * PDO wrapper that adapts MySQL-style SQL when using PostgreSQL.
 */
class HMS_PDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(hms_adapt_sql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $sql = hms_adapt_sql($query);
        if ($fetchMode === null) {
            return parent::query($sql);
        }

        return parent::query($sql, $fetchMode, ...$fetchModeArgs);
    }
}

/**
 * @return array{0: string, 1: array<int, mixed>}
 */
function hms_pdo_dsn_and_options(): array
{
    if (hms_is_pgsql()) {
        $host = (string) HMS_DB_HOST;
        $port = defined('HMS_DB_PORT') ? (int) HMS_DB_PORT : 5432;

        if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
            $host = $m[1];
            $port = (int) $m[2];
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, HMS_DB_NAME);

        $sslMode = defined('HMS_DB_SSLMODE') ? (string) HMS_DB_SSLMODE : '';
        if ($sslMode !== '') {
            $dsn .= ';sslmode=' . $sslMode;
        }

        return [
            $dsn,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 10,
            ],
        ];
    }

    $host = (string) HMS_DB_HOST;
    $port = defined('HMS_DB_PORT') ? (int) HMS_DB_PORT : 3306;

    if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = (int) $m[2];
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        HMS_DB_NAME
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ];

    $useSsl = defined('HMS_DB_SSL') && HMS_DB_SSL;
    if ($useSsl) {
        if (defined('HMS_DB_SSL_CA') && HMS_DB_SSL_CA !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = HMS_DB_SSL_CA;
        } else {
            $options[PDO::MYSQL_ATTR_SSL_CA] = true;
        }

        $verify = !defined('HMS_DB_SSL_VERIFY') || HMS_DB_SSL_VERIFY;
        if (!$verify) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }

    return [$dsn, $options];
}

function hms_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    [$dsn, $options] = hms_pdo_dsn_and_options();

    try {
        $pdo = new HMS_PDO($dsn, HMS_DB_USER, HMS_DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('HMS DB connection failed [' . hms_db_driver() . ' ' . HMS_DB_HOST . '/' . HMS_DB_NAME . ']: ' . $e->getMessage());

        http_response_code(500);
        $debug = getenv('HMS_DEBUG');
        if ($debug === '1' || $debug === 'true') {
            echo 'Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } else {
            echo 'Database connection failed. Please check configuration.';
        }
        exit;
    }

    return $pdo;
}
