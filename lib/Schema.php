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

        $hasEventUrl = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'competitions' AND COLUMN_NAME = 'event_url'"
        )->fetchColumn();
        if ((int) $hasEventUrl === 0) {
            $pdo->exec("ALTER TABLE competitions ADD COLUMN event_url VARCHAR(250) NOT NULL DEFAULT '' AFTER source_key");
        }

        $hasCompetitionName = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'competition_results' AND COLUMN_NAME = 'competition_name'"
        )->fetchColumn();
        if ((int) $hasCompetitionName === 0) {
            $pdo->exec("ALTER TABLE competition_results ADD COLUMN competition_name VARCHAR(120) NOT NULL DEFAULT '' AFTER name_key");
        }

        $startIndexColumns = $pdo->query(
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'competition_results' AND INDEX_NAME = 'uq_start'"
        )->fetchColumn();
        if ($startIndexColumns === null) {
            $pdo->exec(
                'ALTER TABLE competition_results
                 ADD UNIQUE KEY uq_start (competition_id, discipline, license_number, competition_name)'
            );
        } elseif ($startIndexColumns !== 'competition_id,discipline,license_number,competition_name') {
            $pdo->exec(
                'ALTER TABLE competition_results DROP INDEX uq_start,
                 ADD UNIQUE KEY uq_start (competition_id, discipline, license_number, competition_name)'
            );
        }
    }
}
