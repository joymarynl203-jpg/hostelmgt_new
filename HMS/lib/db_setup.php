<?php

declare(strict_types=1);

/**
 * Run PostgreSQL schema from database/schema.postgresql.sql (one-time setup).
 *
 * @return array{executed: int, skipped: int, errors: list<string>}
 */
function hms_run_postgresql_schema(PDO $db): array
{
    $path = dirname(__DIR__) . '/database/schema.postgresql.sql';
    if (!is_readable($path)) {
        throw new RuntimeException('Schema file not found: ' . $path);
    }

    $sql = (string) file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $chunks = preg_split('/;\s*(?=\n|$)/', $sql) ?: [];

    $executed = 0;
    $skipped = 0;
    $errors = [];

    foreach ($chunks as $chunk) {
        $statement = trim($chunk);
        if ($statement === '') {
            continue;
        }

        try {
            $db->exec($statement);
            ++$executed;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'already exists')) {
                ++$skipped;
                continue;
            }
            $errors[] = $msg . ' — SQL: ' . substr(preg_replace('/\s+/', ' ', $statement) ?? $statement, 0, 120);
        }
    }

    return [
        'executed' => $executed,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ];
}

function hms_schema_users_table_exists(PDO $db): bool
{
    try {
        $stmt = $db->query("SELECT to_regclass('public.users') AS t");
        $row = $stmt ? $stmt->fetch() : false;

        return is_array($row) && !empty($row['t']);
    } catch (PDOException $e) {
        return false;
    }
}
