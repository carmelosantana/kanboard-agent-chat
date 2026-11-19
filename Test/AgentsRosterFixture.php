<?php

/**
 * The Agents plugin's roster table, as its schema builds it on each driver, for tests that
 * stand in for Agents without loading its migrations. An auto-increment id on every driver:
 * a bare "INTEGER PRIMARY KEY" only auto-increments on SQLite.
 */
final class AgentsRosterFixture
{
    public static function create(\PDO $pdo): void
    {
        $id = match (DB_DRIVER) {
            'mysql' => 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'postgres' => 'SERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY',
        };
        $pdo->exec("CREATE TABLE IF NOT EXISTS agents (id $id, owner_user_id INTEGER NOT NULL, agent_user_id INTEGER NOT NULL UNIQUE, kind VARCHAR(20) NOT NULL, created_at INTEGER NOT NULL)");
    }
}
