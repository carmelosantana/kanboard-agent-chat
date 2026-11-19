<?php
namespace Kanboard\Plugin\AgentChat\Schema;

const VERSION = 2;

// utf8mb4 (not core's utf8) so chat text keeps emoji; opaque ids (client_msg_id, source, external_id)
// use utf8mb4_bin so UNIQUE matches exactly, as on SQLite and Postgres. TEXT columns carry no DEFAULT.
// UNIQUE keys share their names with the other dialects' unique indexes. MySQL has no partial
// index, so agentchat_rooms_project_uidx covers every kind here (SQLite/Postgres: kind = 'project'
// only); v2 room kinds must keep (kind, project_id) unique or drop this key. Each foreign key has
// its own index, so no UNIQUE key is pinned by "needed in a foreign key constraint".
function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_rooms (
        id INT NOT NULL AUTO_INCREMENT,
        kind VARCHAR(16) NOT NULL DEFAULT \'project\',
        project_id INT NULL,
        name VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        UNIQUE KEY agentchat_rooms_project_uidx (kind, project_id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_messages (
        id INT NOT NULL AUTO_INCREMENT,
        room_id INT NOT NULL,
        sender_kind VARCHAR(16) NOT NULL,
        sender_id INT NULL,
        visibility VARCHAR(16) NOT NULL DEFAULT \'room\',
        private_to INT NULL,
        status VARCHAR(16) NOT NULL DEFAULT \'done\',
        parts MEDIUMTEXT NOT NULL,
        body_text MEDIUMTEXT NOT NULL,
        metadata MEDIUMTEXT NULL,
        client_msg_id VARCHAR(64) COLLATE utf8mb4_bin NULL,
        reply_to INT NULL,
        task_id INT NULL,
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        UNIQUE KEY agentchat_messages_client_msg_uidx (room_id, sender_kind, sender_id, client_msg_id),
        KEY agentchat_messages_room_id_idx (room_id, id),
        KEY agentchat_messages_private_task_idx (private_to, task_id),
        FOREIGN KEY(room_id) REFERENCES agentchat_rooms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_runs (
        id INT NOT NULL AUTO_INCREMENT,
        message_id INT NOT NULL,
        room_id INT NOT NULL,
        asked_by INT NOT NULL,
        stream_token_hash VARCHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT \'pending\',
        stop_requested TINYINT(1) NOT NULL DEFAULT 0,
        started_at BIGINT NULL,
        finished_at BIGINT NULL,
        `usage` TEXT NULL,
        PRIMARY KEY(id),
        UNIQUE KEY agentchat_runs_stream_token_uidx (stream_token_hash),
        FOREIGN KEY(message_id) REFERENCES agentchat_messages(id) ON DELETE CASCADE,
        FOREIGN KEY(room_id) REFERENCES agentchat_rooms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_files (
        id INT NOT NULL AUTO_INCREMENT,
        message_id INT NULL,
        room_id INT NOT NULL,
        uploaded_by INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        mime VARCHAR(100) NOT NULL,
        size INT NOT NULL DEFAULT 0,
        storage_key VARCHAR(255) NOT NULL,
        thumb_key VARCHAR(255) NULL,
        created_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        FOREIGN KEY(message_id) REFERENCES agentchat_messages(id) ON DELETE CASCADE,
        FOREIGN KEY(room_id) REFERENCES agentchat_rooms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS agentchat_knowledge (
        id INT NOT NULL AUTO_INCREMENT,
        project_id INT NOT NULL,
        source VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
        external_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT \'\',
        body MEDIUMTEXT NOT NULL,
        url TEXT NULL,
        author VARCHAR(255) NULL,
        occurred_at BIGINT NULL,
        metadata MEDIUMTEXT NULL,
        created_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(id),
        UNIQUE KEY agentchat_knowledge_source_uidx (project_id, source, external_id),
        KEY agentchat_knowledge_project_idx (project_id),
        FULLTEXT KEY ft_title_body (title, body),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');
}

// The run lifecycle's queries: the reaper and the instance limit (status, started_at), the
// per-user hourly and concurrency limits (asked_by, started_at). They run on every poll.
function version_2($pdo)
{
    $pdo->exec('CREATE INDEX agentchat_runs_status_idx ON agentchat_runs(status, started_at)');
    $pdo->exec('CREATE INDEX agentchat_runs_asker_idx ON agentchat_runs(asked_by, started_at)');
}
