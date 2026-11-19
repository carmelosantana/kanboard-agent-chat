<?php
namespace Kanboard\Plugin\AgentChat\Schema;

const VERSION = 1;

function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_rooms (
        id INTEGER PRIMARY KEY,
        kind VARCHAR(16) NOT NULL DEFAULT \'project\',
        project_id INTEGER NULL,
        name VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at INTEGER NOT NULL DEFAULT 0,
        UNIQUE(kind, project_id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER NOT NULL,
        sender_kind VARCHAR(16) NOT NULL,
        sender_id INTEGER NULL,
        visibility VARCHAR(16) NOT NULL DEFAULT \'room\',
        private_to INTEGER NULL,
        status VARCHAR(16) NOT NULL DEFAULT \'done\',
        parts TEXT NOT NULL,
        body_text TEXT NOT NULL,
        metadata TEXT NULL,
        client_msg_id VARCHAR(64) NULL,
        reply_to INTEGER NULL,
        task_id INTEGER NULL,
        created_at INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0,
        UNIQUE(room_id, sender_kind, sender_id, client_msg_id),
        FOREIGN KEY(room_id) REFERENCES agentchat_rooms(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS agentchat_messages_room_id_idx ON agentchat_messages(room_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS agentchat_messages_private_task_idx ON agentchat_messages(private_to, task_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_runs (
        id INTEGER PRIMARY KEY,
        message_id INTEGER NOT NULL,
        room_id INTEGER NOT NULL,
        asked_by INTEGER NOT NULL,
        stream_token_hash VARCHAR(64) NOT NULL UNIQUE,
        status VARCHAR(16) NOT NULL DEFAULT \'pending\',
        stop_requested INTEGER NOT NULL DEFAULT 0,
        started_at INTEGER NULL,
        finished_at INTEGER NULL,
        "usage" TEXT NULL,
        FOREIGN KEY(message_id) REFERENCES agentchat_messages(id) ON DELETE CASCADE,
        FOREIGN KEY(room_id) REFERENCES agentchat_rooms(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_files (
        id INTEGER PRIMARY KEY,
        message_id INTEGER NULL,
        room_id INTEGER NOT NULL,
        uploaded_by INTEGER NOT NULL,
        name VARCHAR(255) NOT NULL,
        mime VARCHAR(100) NOT NULL,
        size INTEGER NOT NULL DEFAULT 0,
        storage_key VARCHAR(255) NOT NULL,
        thumb_key VARCHAR(255) NULL,
        created_at INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(message_id) REFERENCES agentchat_messages(id) ON DELETE CASCADE,
        FOREIGN KEY(room_id) REFERENCES agentchat_rooms(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_knowledge (
        id INTEGER PRIMARY KEY,
        project_id INTEGER NOT NULL,
        source VARCHAR(64) NOT NULL,
        external_id VARCHAR(191) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT \'\',
        body TEXT NOT NULL,
        url TEXT NULL,
        author VARCHAR(255) NULL,
        occurred_at INTEGER NULL,
        metadata TEXT NULL,
        created_at INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0,
        UNIQUE(project_id, source, external_id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )');

    // FTS5 external-content index over title and body, kept in step by triggers.
    $pdo->exec('CREATE VIRTUAL TABLE IF NOT EXISTS agentchat_knowledge_fts USING fts5(
        title, body, content=\'agentchat_knowledge\', content_rowid=\'id\'
    )');
    $pdo->exec('CREATE TRIGGER IF NOT EXISTS agentchat_knowledge_ai AFTER INSERT ON agentchat_knowledge BEGIN
        INSERT INTO agentchat_knowledge_fts(rowid, title, body) VALUES (new.id, new.title, new.body);
    END');
    $pdo->exec('CREATE TRIGGER IF NOT EXISTS agentchat_knowledge_ad AFTER DELETE ON agentchat_knowledge BEGIN
        INSERT INTO agentchat_knowledge_fts(agentchat_knowledge_fts, rowid, title, body) VALUES (\'delete\', old.id, old.title, old.body);
    END');
    $pdo->exec('CREATE TRIGGER IF NOT EXISTS agentchat_knowledge_au AFTER UPDATE ON agentchat_knowledge BEGIN
        INSERT INTO agentchat_knowledge_fts(agentchat_knowledge_fts, rowid, title, body) VALUES (\'delete\', old.id, old.title, old.body);
        INSERT INTO agentchat_knowledge_fts(rowid, title, body) VALUES (new.id, new.title, new.body);
    END');
}
