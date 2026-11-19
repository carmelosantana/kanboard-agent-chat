<?php

namespace Kanboard\Plugin\AgentChat\Model;

use InvalidArgumentException;
use Kanboard\Core\Base;
use RuntimeException;

/**
 * Per-project knowledge: documents pushed in from outside (Save-it transcripts first), keyed by
 * (project_id, source, external_id) and searched with the database's own full-text engine.
 *
 * Every field is validated here, since SQLite does not enforce VARCHAR sizes; anything invalid
 * is an InvalidArgumentException with a plain message. Writes are a lookup then UPDATE or
 * INSERT, never REPLACE (which would change the id and skip SQLite's FTS delete trigger). Each
 * statement is atomic on its own and the FTS triggers run inside it, so no transaction is
 * needed; an INSERT that loses a race on the unique key re-reads the winner and UPDATEs it.
 */
class KnowledgeModel extends Base
{
    public const TABLE = 'agentchat_knowledge';
    public const MAX_SOURCE = 64;
    public const MAX_EXTERNAL_ID = 191;
    public const MAX_TITLE = 255;
    public const MAX_AUTHOR = 255;
    public const MAX_URL = 2048;
    public const MAX_BODY = 200000;
    public const MAX_METADATA_BYTES = 16384;
    public const MAX_QUERY = 200;
    public const MAX_SNIPPET = 300;
    public const DEFAULT_LIMIT = 8;
    public const MAX_LIMIT = 20;

    private const FIELDS = ['title', 'body', 'url', 'author', 'occurred_at', 'metadata'];
    private const MAX_QUERY_WORDS = 32;

    /**
     * Creates or updates the document under (project, source, external id) and returns its id.
     * Fields: title (required), body, url (http/https), author, occurred_at (unix time), metadata (array).
     */
    public function upsert(int $projectId, string $source, string $externalId, array $doc): int
    {
        $this->assertKey($projectId, $source, $externalId);
        $values = $this->validateDoc($doc);
        $now = time();

        $id = $this->findId($projectId, $source, $externalId);
        if ($id !== null && $this->update($id, $values, $now)) {
            return $id;
        }

        $inserted = $this->db->table(self::TABLE)->persist($values + [
            'project_id' => $projectId,
            'source' => $source,
            'external_id' => $externalId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted) {
            return (int) $inserted;
        }

        // Lost the race on agentchat_knowledge_source_uidx: update the row that won.
        $id = $this->findId($projectId, $source, $externalId);
        if ($id !== null && $this->update($id, $values, $now)) {
            return $id;
        }
        throw new RuntimeException("Could not store knowledge document $source/$externalId in project #$projectId.");
    }

    /** True when a document was deleted. */
    public function delete(int $projectId, string $source, string $externalId): bool
    {
        $this->assertKey($projectId, $source, $externalId);

        return $this->db->table(self::TABLE)
            ->eq('project_id', $projectId)
            ->eq('source', $source)
            ->eq('external_id', $externalId)
            ->remove();
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->table(self::TABLE)->eq('id', $id)->findOne();
        return $row ?: null;
    }

    /**
     * Full-text search in one project, best match first. The query is reduced to its words
     * (letters, digits, underscore), so no engine syntax reaches the database; an empty or
     * over-long query returns []. `limit` is clamped to 1..20.
     *
     * Matching follows each engine: SQLite FTS5 matches any word (ranked by bm25), MySQL
     * natural language mode likewise, Postgres plainto_tsquery needs every word.
     *
     * @return list<array{id: int, title: string, url: string, snippet: string, occurred_at: int, source: string}>
     */
    public function search(int $projectId, string $query, ?string $source = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = trim($query);
        if ($query === '' || ! mb_check_encoding($query, 'UTF-8') || mb_strlen($query, 'UTF-8') > self::MAX_QUERY) {
            return [];
        }
        preg_match_all('/[\p{L}\p{N}_]+/u', $query, $m);
        $words = array_slice(array_values(array_unique($m[0])), 0, self::MAX_QUERY_WORDS);
        if ($words === []) {
            return [];
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $params = [];
        switch ($this->db->getConnection()->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $match = 'MATCH(k.title, k.body) AGAINST (? IN NATURAL LANGUAGE MODE)';
                $sql = "SELECT k.id, k.title, k.url, k.body, k.occurred_at, k.source FROM agentchat_knowledge k WHERE $match AND k.project_id = ?";
                $params = [implode(' ', $words), $projectId];
                $order = " ORDER BY $match DESC, k.id DESC";
                $orderParams = [implode(' ', $words)];
                break;
            case 'pgsql':
                $sql = "SELECT k.id, k.title, k.url, k.body, k.occurred_at, k.source FROM agentchat_knowledge k WHERE k.search @@ plainto_tsquery('simple', ?) AND k.project_id = ?";
                $params = [implode(' ', $words), $projectId];
                $order = " ORDER BY ts_rank(k.search, plainto_tsquery('simple', ?)) DESC, k.id DESC";
                $orderParams = [implode(' ', $words)];
                break;
            default:
                // Each word as an FTS5 string ("…", inner quotes doubled), joined with OR.
                $fts = implode(' OR ', array_map(static fn (string $w): string => '"'.str_replace('"', '""', $w).'"', $words));
                $sql = 'SELECT k.id, k.title, k.url, k.body, k.occurred_at, k.source FROM agentchat_knowledge_fts JOIN agentchat_knowledge k ON k.id = agentchat_knowledge_fts.rowid WHERE agentchat_knowledge_fts MATCH ? AND k.project_id = ?';
                $params = [$fts, $projectId];
                $order = ' ORDER BY agentchat_knowledge_fts.rank, k.id DESC';
                $orderParams = [];
        }
        if ($source !== null) {
            $sql .= ' AND k.source = ?';
            $params[] = $source;
        }
        $sql .= $order.' LIMIT '.$limit;

        $rows = $this->db->execute($sql, array_merge($params, $orderParams))->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'url' => (string) $row['url'],
            'snippet' => $this->snippet((string) $row['body'], $words),
            'occurred_at' => (int) $row['occurred_at'],
            'source' => (string) $row['source'],
        ], $rows);
    }

    /** The row id under a key; protected so a test can simulate losing the insert race. */
    protected function findId(int $projectId, string $source, string $externalId): ?int
    {
        $id = $this->db->table(self::TABLE)
            ->eq('project_id', $projectId)
            ->eq('source', $source)
            ->eq('external_id', $externalId)
            ->findOneColumn('id');
        return $id ? (int) $id : null;
    }

    /** False when the row is gone (deleted between lookup and update). */
    private function update(int $id, array $values, int $now): bool
    {
        if (! $this->db->table(self::TABLE)->eq('id', $id)->update($values + ['updated_at' => $now])) {
            return false;
        }
        return $this->db->table(self::TABLE)->eq('id', $id)->exists();
    }

    /** Plain text around the first matching word, whitespace collapsed, at most 300 characters. */
    private function snippet(string $body, array $words): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $body));
        if (mb_strlen($text, 'UTF-8') <= self::MAX_SNIPPET) {
            return $text;
        }
        $at = null;
        foreach ($words as $word) {
            $pos = mb_stripos($text, $word, 0, 'UTF-8');
            if ($pos !== false && ($at === null || $pos < $at)) {
                $at = $pos;
            }
        }
        $start = max(0, ($at ?? 0) - 80);
        $prefix = $start > 0 ? '…' : '';
        $room = self::MAX_SNIPPET - mb_strlen($prefix, 'UTF-8');
        $cut = mb_substr($text, $start, $room, 'UTF-8');
        if ($start + $room < mb_strlen($text, 'UTF-8')) {
            $cut = mb_substr($cut, 0, $room - 1, 'UTF-8').'…';
        }
        return $prefix.$cut;
    }

    private function assertKey(int $projectId, string $source, string $externalId): void
    {
        if ($projectId < 1) {
            throw new InvalidArgumentException('project_id must be a positive integer.');
        }
        $this->singleLine($source, 'source', self::MAX_SOURCE, true);
        $this->singleLine($externalId, 'external_id', self::MAX_EXTERNAL_ID, true);
    }

    /** @return array{title: string, body: string, url: string, author: string, occurred_at: ?int, metadata: ?string} */
    private function validateDoc(array $doc): array
    {
        if ($unknown = array_diff(array_keys($doc), self::FIELDS)) {
            throw new InvalidArgumentException('Unknown knowledge field: '.implode(', ', array_map('strval', $unknown)).'.');
        }
        $title = trim($this->string($doc['title'] ?? '', 'title'));
        $this->singleLine($title, 'title', self::MAX_TITLE, true);

        $body = $this->string($doc['body'] ?? '', 'body');
        if (! mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) {
            throw new InvalidArgumentException('body must be valid UTF-8 without NUL bytes.');
        }
        if (mb_strlen($body, 'UTF-8') > self::MAX_BODY) {
            throw new InvalidArgumentException('body is limited to '.self::MAX_BODY.' characters.');
        }

        $url = trim($this->string($doc['url'] ?? '', 'url'));
        if ($url !== '') {
            $this->singleLine($url, 'url', self::MAX_URL, false);
            if (! self::isHttpUrl($url)) {
                throw new InvalidArgumentException('url must be an http or https address.');
            }
        }

        $author = trim($this->string($doc['author'] ?? '', 'author'));
        $this->singleLine($author, 'author', self::MAX_AUTHOR, false);

        $occurredAt = $doc['occurred_at'] ?? 0;
        if (is_string($occurredAt) && ctype_digit($occurredAt)) {
            $occurredAt = (int) $occurredAt;
        }
        if (! is_int($occurredAt) || $occurredAt < 0) {
            throw new InvalidArgumentException('occurred_at must be a unix timestamp (an integer of 0 or more).');
        }

        $metadata = $doc['metadata'] ?? [];
        if (! is_array($metadata)) {
            throw new InvalidArgumentException('metadata must be a JSON object or array.');
        }
        $metadataJson = null;
        if ($metadata !== []) {
            try {
                $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                throw new InvalidArgumentException('metadata cannot be stored as JSON.');
            }
            if (strlen($metadataJson) > self::MAX_METADATA_BYTES) {
                throw new InvalidArgumentException('metadata is limited to '.self::MAX_METADATA_BYTES.' bytes of JSON.');
            }
        }

        return [
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'author' => $author,
            'occurred_at' => $occurredAt === 0 ? null : $occurredAt,
            'metadata' => $metadataJson,
        ];
    }

    /** An absolute http(s) URL with a host and no whitespace, backslash or control characters. */
    public static function isHttpUrl(string $url): bool
    {
        if (preg_match('/[\s\\\\\p{Cc}]/u', $url) || ! preg_match('~^https?://~i', $url)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '';
    }

    private function string(mixed $value, string $name): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("$name must be a string.");
        }
        return $value;
    }

    /** Clean UTF-8, no control characters, at most $max characters; non-blank when required. */
    private function singleLine(string $value, string $name, int $max, bool $required): void
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/\p{Cc}/u', $value)) {
            throw new InvalidArgumentException("$name must be valid UTF-8 without control characters.");
        }
        if ($required && trim($value) === '') {
            throw new InvalidArgumentException("$name is required.");
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw new InvalidArgumentException("$name is limited to $max characters.");
        }
    }
}
