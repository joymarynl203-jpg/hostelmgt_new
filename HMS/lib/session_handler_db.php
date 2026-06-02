<?php

declare(strict_types=1);

/**
 * Store PHP sessions in the database so they survive Render container restarts.
 */
final class HmsDatabaseSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private bool $tableReady = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool
    {
        $this->ensureTable();

        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('
            SELECT data
            FROM hms_sessions
            WHERE id = ?
              AND last_activity >= (NOW() - INTERVAL \'7 days\')
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? (string) $row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $this->ensureTable();

        if (hms_is_pgsql()) {
            $sql = '
                INSERT INTO hms_sessions (id, data, last_activity)
                VALUES (?, ?, NOW())
                ON CONFLICT (id) DO UPDATE SET
                    data = EXCLUDED.data,
                    last_activity = EXCLUDED.last_activity
            ';
        } else {
            $sql = '
                INSERT INTO hms_sessions (id, data, last_activity)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    data = VALUES(data),
                    last_activity = NOW()
            ';
        }

        return $this->pdo->prepare($sql)->execute([$id, $data]);
    }

    public function destroy(string $id): bool
    {
        $this->ensureTable();

        return $this->pdo->prepare('DELETE FROM hms_sessions WHERE id = ?')->execute([$id]);
    }

    public function gc(int $maxlifetime): int|false
    {
        $this->ensureTable();
        $seconds = max(1, $maxlifetime);
        if (hms_is_pgsql()) {
            $stmt = $this->pdo->prepare("
                DELETE FROM hms_sessions
                WHERE last_activity < (NOW() - INTERVAL '{$seconds} seconds')
            ");
            $stmt->execute();
        } else {
            $stmt = $this->pdo->prepare('
                DELETE FROM hms_sessions
                WHERE last_activity < (NOW() - INTERVAL ? SECOND)
            ');
            $stmt->execute([$seconds]);
        }

        return $stmt->rowCount();
    }

    private function ensureTable(): void
    {
        if ($this->tableReady) {
            return;
        }

        if (hms_is_pgsql()) {
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS hms_sessions (
                    id VARCHAR(128) PRIMARY KEY,
                    data TEXT NOT NULL,
                    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ');
            $this->pdo->exec('
                CREATE INDEX IF NOT EXISTS idx_hms_sessions_activity ON hms_sessions (last_activity)
            ');
        } else {
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS hms_sessions (
                    id VARCHAR(128) PRIMARY KEY,
                    data TEXT NOT NULL,
                    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_hms_sessions_activity (last_activity)
                )
            ');
        }

        $this->tableReady = true;
    }
}
