# AgentChat (Kanboard plugin)

Project chat rooms for people and agents, with an AI project bot that answers from the board and the
project's knowledge.

> Status: 0.1.0 in development. This release holds the skeleton: the database schema, the AI gate and
> the vendored chatparts UI kit. The chat UI and the bot arrive in later steps.

## Install

Unzip `AgentChat-<version>.zip` into `plugins/AgentChat/` (the zip's single top-level folder is
`AgentChat`). The tables are created on first load. Requires Kanboard `>=1.2.47` and PHP `>=8.4`, and
works on SQLite, MySQL/MariaDB and Postgres 12 or later (knowledge search uses a generated column).

## Recommended plugins

| Plugin | Minimum | Adds |
|---|---|---|
| AiConnector | 1.2.0 | Project bot answers |
| Agents | 0.5.0 | Agent badges |
| Presence | 1.0.1 | Online dots |

Without AiConnector (or with an older one, or no configured provider) the room still works for people
and agents; the bot is simply not offered.

## Data

| Table | Holds |
|---|---|
| `agentchat_rooms` | one `project` room per project |
| `agentchat_messages` | room and private (task drawer) messages; the autoincrement id is the poll cursor |
| `agentchat_runs` | bot runs: hashed single-use stream id, status, stop flag, usage |
| `agentchat_files` | uploaded images, stored through Kanboard's object storage |
| `agentchat_knowledge` | searchable project knowledge, with full-text search (SQLite FTS5, MySQL FULLTEXT, Postgres tsvector) |

Deleting a project deletes its room, messages, runs, files rows and knowledge.

Knowledge search uses each database's own full-text engine, so results can differ by dialect: SQLite
FTS5 (`unicode61` tokens), MySQL/MariaDB InnoDB FULLTEXT (words under 3 characters and its stopwords
are not indexed) and Postgres `simple` text search (no stemming). Queries are reduced to their words
(no search syntax reaches the engine); SQLite and MySQL match any of the words, ranked, while Postgres
(`plainto_tsquery`) needs all of them.

## Knowledge API

Transcript agents (Save-it first) push documents into a project over Kanboard's JSON-RPC API
(`jsonrpc.php`). A document is keyed by `(project_id, source, external_id)`, so pushing it again
updates it in place.

| Procedure | Params | Returns |
|---|---|---|
| `knowledgeUpsert` | `project_id`, `source` (≤ 64), `external_id` (≤ 191), `title` (≤ 255), `body` (≤ 200 000 characters, Markdown), `url` (http/https, optional), `author` (≤ 255, optional), `occurred_at` (unix time, optional), `metadata` (JSON object, ≤ 16 KB, optional) | `{"ok": true, "id": <id>}` |
| `knowledgeDelete` | `project_id`, `source`, `external_id` | `true` if a document was deleted |

Who may call them:

- **A user** (username with password or personal API token): a manager of the project, or an app
  admin. Anyone else, an inactive user, or a project that does not exist gets `403 Forbidden`. A
  dedicated Kanboard user with the manager role on the archive project is the intended caller.
- **The application token** (user `jsonrpc`): Kanboard gives it full API access, so it may write
  into any existing project. Keep it for trusted automation.

Invalid params come back as `-32602 Invalid params` with a plain reason in `data`.

Each document has a read-only page at `/agentchat/knowledge/<id>`, open to anyone who can view its
project; the bot links its citations there.

To try it on a dev stack, push the sample transcripts as a project manager:

```bash
KANBOARD_URL=http://localhost:8081/jsonrpc.php KANBOARD_USER=<manager> KANBOARD_TOKEN=<token> \
  php scripts/import-saveit-fixture.php <project_id> [Test/fixtures/saveit-sample.json]
```

## chatparts

The UI kit lives in `vendor-chatparts/`, a hash-checked copy of the chatparts kit. Do not edit it by
hand; refresh it with:

```bash
scripts/sync-chatparts.sh ~/Projects/chatparts
```

The copy is verified against `vendor-chatparts/CHECKSUMS`, and `Test/ChatPartsVendorTest.php`
re-checks it.

## License

MIT. chatparts' own license and third-party notices ship in `vendor-chatparts/`.
