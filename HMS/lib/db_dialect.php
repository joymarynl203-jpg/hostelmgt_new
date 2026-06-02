<?php

function hms_db_driver(): string
{
    if (defined('HMS_DB_DRIVER') && HMS_DB_DRIVER !== '') {
        return strtolower((string) HMS_DB_DRIVER);
    }

    return 'mysql';
}

function hms_is_pgsql(): bool
{
    return hms_db_driver() === 'pgsql';
}

function hms_table_exists(PDO $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (hms_is_pgsql()) {
        $stmt = $db->prepare('SELECT to_regclass(?) AS t');
        $stmt->execute(['public.' . $table]);
        $row = $stmt->fetch();
        $cache[$table] = is_array($row) && !empty($row['t']);
    } else {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($table));
        $cache[$table] = (bool) ($stmt && $stmt->fetch());
    }

    return $cache[$table];
}

function hms_table_has_column(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (hms_is_pgsql()) {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = ?
              AND column_name = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool) $stmt->fetch();
    } else {
        $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $db->quote($column));
        $cache[$key] = (bool) ($stmt && $stmt->fetch());
    }

    return $cache[$key];
}

/**
 * Adapt MySQL-oriented SQL for PostgreSQL (string literals, date functions).
 */
function hms_adapt_sql(string $sql): string
{
    if (!hms_is_pgsql()) {
        return $sql;
    }

    $sql = preg_replace('/=\s*"([a-z][a-z0-9_]*)"/i', "='$1'", $sql);
    $sql = preg_replace('/,\s*"([a-z][a-z0-9_]*)"/i', ", '$1'", $sql);
    $sql = preg_replace('/\(\s*"([a-z][a-z0-9_]*)"/i', "('$1'", $sql);
    $sql = preg_replace('/\s+"([a-z][a-z0-9_]*)"\s*,/i', " '$1',", $sql);

    $sql = preg_replace(
        '/DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+MINUTE\s*\)/i',
        "NOW() - INTERVAL '$1 minutes'",
        $sql
    );

    $sql = preg_replace(
        '/DATE_ADD\s*\(\s*UTC_TIMESTAMP\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+HOUR\s*\)/i',
        "(NOW() AT TIME ZONE 'utc') + INTERVAL '$1 hour'",
        $sql
    );

    $sql = preg_replace(
        '/NOW\s*\(\s*\)\s*-\s*INTERVAL\s+(\d+)\s+DAY\b/i',
        "NOW() - INTERVAL '$1 days'",
        $sql
    );

    $sql = str_ireplace('UTC_TIMESTAMP()', "(NOW() AT TIME ZONE 'utc')", $sql);

    $sql = preg_replace(
        '/GROUP_CONCAT\s*\(\s*(.+?)\s+ORDER BY\s+(.+?)\s+SEPARATOR\s+", "\s*\)/is',
        "STRING_AGG($1, ', ' ORDER BY $2)",
        $sql
    );

    return $sql;
}
