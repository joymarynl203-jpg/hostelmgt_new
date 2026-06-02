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
