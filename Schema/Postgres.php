<?php
namespace Kanboard\Plugin\AgentChat\Schema;

const VERSION = 1;

function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_rooms (
        id SERIAL PRIMARY KEY,
        kind VARCHAR(16) NOT NULL DEFAULT \'project\',
        project_id INTEGER NULL REFERENCES projects(id) ON DELETE CASCADE,
        name VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at BIGINT NOT NULL DEFAULT 0,
        UNIQUE(kind, project_id)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_messages (
        id SERIAL PRIMARY KEY,
        room_id INTEGER NOT NULL REFERENCES agentchat_rooms(id) ON DELETE CASCADE,
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
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0,
        UNIQUE(room_id, sender_kind, sender_id, client_msg_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS agentchat_messages_room_id_idx ON agentchat_messages(room_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS agentchat_messages_private_task_idx ON agentchat_messages(private_to, task_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_runs (
        id SERIAL PRIMARY KEY,
        message_id INTEGER NOT NULL REFERENCES agentchat_messages(id) ON DELETE CASCADE,
        room_id INTEGER NOT NULL REFERENCES agentchat_rooms(id) ON DELETE CASCADE,
        asked_by INTEGER NOT NULL,
        stream_token_hash VARCHAR(64) NOT NULL UNIQUE,
        status VARCHAR(16) NOT NULL DEFAULT \'pending\',
        stop_requested BOOLEAN NOT NULL DEFAULT FALSE,
        started_at BIGINT NULL,
        finished_at BIGINT NULL,
        usage TEXT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_files (
        id SERIAL PRIMARY KEY,
        message_id INTEGER NULL REFERENCES agentchat_messages(id) ON DELETE CASCADE,
        room_id INTEGER NOT NULL REFERENCES agentchat_rooms(id) ON DELETE CASCADE,
        uploaded_by INTEGER NOT NULL,
        name VARCHAR(255) NOT NULL,
        mime VARCHAR(100) NOT NULL,
        size INTEGER NOT NULL DEFAULT 0,
        storage_key VARCHAR(255) NOT NULL,
        thumb_key VARCHAR(255) NULL,
        created_at BIGINT NOT NULL DEFAULT 0
    )');

    // Generated tsvector ("simple": no stemming, language-neutral) with a GIN index.
    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_knowledge (
        id SERIAL PRIMARY KEY,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        source VARCHAR(64) NOT NULL,
        external_id VARCHAR(191) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT \'\',
        body TEXT NOT NULL,
        url TEXT NULL,
        author VARCHAR(255) NULL,
        occurred_at BIGINT NULL,
        metadata TEXT NULL,
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0,
        search tsvector GENERATED ALWAYS AS (to_tsvector(\'simple\'::regconfig, coalesce(title, \'\') || \' \' || coalesce(body, \'\'))) STORED,
        UNIQUE(project_id, source, external_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS agentchat_knowledge_search_idx ON agentchat_knowledge USING GIN (search)');
}
