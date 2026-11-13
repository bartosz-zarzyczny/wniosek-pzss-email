<?php
declare(strict_types=1);

final class Schema
{
    /** Tworzy brakujące tabele i kolumny (bezpieczne do wielokrotnego uruchamiania). */
    public static function ensure(PDO $pdo): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../database/schema.sql');
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }

        $hasColumn = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'competitions' AND COLUMN_NAME = 'source_key'"
        )->fetchColumn();
        if ((int) $hasColumn === 0) {
            $pdo->exec('ALTER TABLE competitions ADD COLUMN source_key VARCHAR(255) NULL, ADD UNIQUE KEY uq_source (source_key)');
        }
    }
}
