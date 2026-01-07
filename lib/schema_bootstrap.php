<?php

declare(strict_types=1);

if (!function_exists('ensureSchema')) {
    function ensureSchema(PDO $pdo): void
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') {
            throw new RuntimeException('Database is not selected.');
        }

        $requiredTables = ['dialogs', 'messages'];
        $missingTables = [];

        $checkStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );

        foreach ($requiredTables as $table) {
            $checkStmt->execute([
                'schema' => $database,
                'table' => $table,
            ]);
            $exists = (int) $checkStmt->fetchColumn();
            if ($exists === 0) {
                $missingTables[] = $table;
            }
        }

        if ($missingTables !== []) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS dialogs (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  telegram_user_id BIGINT NOT NULL,
                  status ENUM('done', 'error') NOT NULL,
                  welcomed TINYINT(1) NOT NULL DEFAULT 0,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  finished_at DATETIME NULL
                )"
            );

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS messages (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  dialog_id INT NOT NULL,
                  role ENUM('user', 'assistant', 'system') NOT NULL,
                  content TEXT NOT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY (dialog_id) REFERENCES dialogs(id)
                )"
            );
        }

        $columnStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $columnStmt->execute([
            'schema' => $database,
            'table' => 'dialogs',
            'column' => 'welcomed',
        ]);
        $hasColumn = (int) $columnStmt->fetchColumn();
        if ($hasColumn === 0) {
            $pdo->exec('ALTER TABLE dialogs ADD COLUMN welcomed TINYINT(1) NOT NULL DEFAULT 0');
        }
    }
}
