# AgentChat (Kanboard plugin)

Project chat rooms for people and agents, with an AI project bot that answers from the board and the
project's knowledge.

## What it does

- **A Chat tab on every project** (beside Board and List): one room per project. Members and agents
  post; the room refreshes every 4 seconds (paused while the tab is hidden). A rail lists the
  project's people and agents; on a phone it sits behind a People button.
- **The project bot answers `@bot`.** The answer streams to the person who asked, with its reasoning,
  a tool strip ("Searched 3 sources · 2 tools"), numbered citations `[n]`, a sources row and a receipt
  (model, tools, who it answered for and in which role, or why it stopped). Everyone else sees it
  appear through the poll. Stop ends an answer and keeps what was written so far.
- **"Ask the bot" on the task page** opens a drawer: a private thread per person and task, reopened
  where it left off, answered with that task first. **Share to room** posts the latest question and
  answer (images included) into the project room, once.
- **The bot reads as the asker.** Its four tools are read-only: `search_tasks`, `get_task`,
  `search_comments` and `search_knowledge`, each limited to the room's project and to what the asker
  can open. It cites only what those tools returned, never writes, and has no network tools. Admins
  gain nothing through it.
- **A Claude-style composer**: the box grows with the message, Enter sends and Shift+Enter starts a
  new line, images attach by button, paste or drop and show as removable thumbnails, and a context
  ring shows how much of the model's window the next question will use (instructions, history,
  tools, sources, the message and its images, the reply reserve). Send turns into Stop while an
  answer streams.
- **Project knowledge**: transcript agents (Save-it first) push documents over JSON-RPC; the bot
  searches them with the database's full-text engine, and each document has a read-only page that
  citations link to.
- **Agents in the room**: JSON-RPC procedures let agents read and post as themselves, `@bot` included.

The UI is the [chatparts](https://github.com/carmelosantana/chatparts) kit (server-rendered PHP views,
htmx, plain JS and CSS, a Lucide icon sprite), vendored into `vendor-chatparts/`. No build step and no
CDN: every asset is served from Kanboard.

## Install

Unzip `AgentChat-<version>.zip` into `plugins/AgentChat/` (the zip's single top-level folder is
`AgentChat`). Installing from ModMenu works once AgentChat is listed in the plugin directory. The tables are created on first load. Requires Kanboard
`>=1.2.47` and PHP `>=8.4`, and works on SQLite, MySQL/MariaDB and Postgres 12 or later (knowledge
search uses a generated column).

AgentChat recommends, but does not require:

| Plugin | Minimum | Adds |
|---|---|---|
| [AiConnector](https://github.com/carmelosantana/kanboard-ai-connector) | `1.2.0` | the project bot (AiConnector 1.2.0 bundles php-agents 0.17) |
| [Agents](https://github.com/carmelosantana/kanboard-agents) | `0.5.0` | Agent badges on agents' messages and an Agents group in the rail |
| [Presence](https://github.com/carmelosantana/kanboard-presence) | `1.0.1` | online dots in the rail (declared; 0.1.0 does not draw them yet) |

Without Agents everyone is a person. Without AiConnector, see
[Without AiConnector](#without-aiconnector).

After install, if Kanboard sits behind a proxy, read [Streaming behind a proxy](#streaming-behind-a-proxy)
and ask the bot something long: the answer should appear a few words at a time, not all at once.

## Permissions

Membership is Kanboard's own: the project role (direct or through a group; custom project roles count
as members) and the app role. Inactive users get nothing.

| | Viewer | Member | Manager | App admin |
|---|---|---|---|---|
| Read the room | yes | yes | yes | yes |
| Post, attach images, `chatSendMessage` | no (no composer) | yes | yes | yes |
| Ask the bot in the room (`@bot`) | no | yes, by default | yes | yes |
| Ask the bot in the task drawer | only when the project allows everyone (text only, no Share to room) | yes, by default | yes | yes |
| Project chat settings | no | no | yes | yes |
| Chat limits (Settings) | no | no | no | yes |
| `knowledgeUpsert`, `knowledgeDelete` | no | no | yes | yes |

- **Who may ask** is per project: managers only, members and managers (default), or everyone with
  access, viewers included. The bot must also be on for the project and AiConnector ready. Asking in
  the room means posting there, so `@bot` in the room stays with members and up; a viewer under
  "everyone" asks in the task drawer, in their private thread, without images (a drawer upload from
  them is refused and nothing is stored) and without Share to room. Under the default and "managers
  only" settings a viewer's drawer says "The bot is not available here." A member outside the ask
  setting can still talk; `@bot` is simply not offered.
- **Private drawer threads** are readable only by the person who asked. Share to room copies them as
  two new room messages, with their own copies of any images.
- **The bot's reads run as the asker**: every tool re-checks the asker's access to the room's project,
  and a source the asker cannot open is never cited.
- **Images** are served by AgentChat after checking the file's room, project and, for drawer images,
  its private owner, with the sniffed type, `nosniff` and `Cache-Control: private`.
- **Knowledge pages** (`/agentchat/knowledge/<id>`) are open to anyone who can view the document's
  project.

## Settings

### Project: Project settings → Chat

For project managers and app admins.

| Setting | Default | Does |
|---|---|---|
| The project bot answers @bot and the task drawer | on | off removes the bot from this project |
| Who may ask the bot | Members and managers | Managers only / Members and managers / Everyone with access, viewers included |
| AI profile | AiConnector's default | which AiConnector profile (provider and model) answers here; listed only when AiConnector is installed |
| Send images to the bot | on | off keeps images in the chat for people while the bot answers from the text alone; the composer then says "The bot won't see images" |

Stored in `project_metadata` as `agentchat_bot_enabled`, `agentchat_ask_role`, `agentchat_ai_profile`
and `agentchat_bot_images`.

### Instance: Settings → Chat limits

For app admins. Every limit is a whole number of 1 or more; no limit can be switched off.

| Limit | Default |
|---|---|
| Questions per person per hour | 30 |
| Answers at once per person | 1 |
| Answers at once on this instance | 4 |
| Time per answer (seconds) | 90 |
| Steps per answer | 6 |
| Tool calls per answer | 8 |
| Tool calls per step | 4 |
| Answer size (bytes) | 262 144 (256 KB) |

Stored in Kanboard's config table as `agentchat_*`. A question over a limit is still posted, with a
notice saying which limit it hit and no answer. A bot run that crashes or hangs is ended as an error
once the time limit plus a grace period has passed.

The input to the model is also held to its context window (from AiConnector's model catalog) less a
reply reserve of 4 000 tokens (or a quarter of a small window). Room history is the last 40 messages,
oldest dropped first when they do not fit; retrieved sources are capped at 8 000 tokens (or a quarter
of a small window). A question too long for the model is refused before any provider call.

**Each streaming answer holds one PHP-FPM worker for its whole length.** Keep *Answers at once on this
instance* below `pm.max_children`: the official Kanboard image runs 20 workers, so the default 4 leaves
16 for the board. The settings page repeats this warning.

## Images

Up to 4 images per message, each at most 5 MB and 40 megapixels, PNG, JPEG, WebP or GIF (checked by
their bytes, not their name; SVG is refused). They are stored through Kanboard's object storage under
`agentchat/` with a thumbnail, and removed with their message, room or project.

People always see them. The bot gets them only while **Send images to the bot** is on. When the
provider refuses image input, the question is asked again once with the text alone and the answer
says the bot could not see the images.

**Memory: with "Send images to the bot" on (the default), raise PHP's `memory_limit` to 256M.** One
answer with four ~5 MB images peaked at 88.9 MiB of PHP memory in a measured run with php-agents'
Anthropic provider (the images travel base64-encoded in the request body, about 26 MB), against the
128M default of the official Kanboard image. That fits, but with little room to spare. In that image,
set it in a PHP ini file, for example `/etc/php84/conf.d/zz-memory.ini` with `memory_limit = 256M`,
and restart the container. If you see "Allowed memory size exhausted" in the log, raise
`memory_limit` for PHP-FPM.

## Streaming behind a proxy

Answers stream as server-sent events from `GET /agentchat/stream?sid=…` (with URL rewriting off,
`?controller=StreamController&action=stream&plugin=AgentChat`). The stream sends
`X-Accel-Buffering: no`, turns off output buffering and compression, and writes a `:` heartbeat every
15 seconds between events. An answer is limited to 90 seconds by default.

The official Kanboard image needs no change: its nginx honours `X-Accel-Buffering: no` and does not
gzip `text/event-stream`. What can still buffer is a proxy in front of it.

**nginx** in front of Kanboard:

```nginx
location /agentchat/stream {
    proxy_pass http://kanboard;          # your Kanboard upstream
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_read_timeout 120s;             # above the 90 s answer limit
}
```

Raise `proxy_read_timeout` if you raise *Time per answer*.

**Traefik** streams by default. Do not put a `buffering` middleware on this route, and if a `compress`
middleware applies, keep event streams out of it:

```yaml
labels:
  # kb-compress: whatever your compress middleware is called
  - "traefik.http.middlewares.kb-compress.compress.excludedcontenttypes=application/grpc,text/event-stream"
  - "traefik.http.services.kanboard.loadbalancer.responseforwarding.flushinterval=100ms"
```

`excludedcontenttypes` replaces the middleware's default exclusion list instead of adding to it, so
the value has to carry the defaults too: copy them from the compress page of your Traefik version's
documentation (`application/grpc` above stands for them) and add `text/event-stream`. If you would
rather not touch the list, leave the `compress` middleware off the Kanboard router.

**Cloudflare** (proxied DNS or a tunnel) passes `text/event-stream` through as it arrives. Its proxy
timeout is 100 seconds without a response from Kanboard; the stream answers at once and its 15 s
heartbeat keeps a waiting stream inside it. Do not cache `/agentchat/*` with a Cache Rule.

If answers still arrive all at once, something between the browser and PHP-FPM is buffering: check
each hop for response buffering and compression of `text/event-stream`.

## Knowledge API

Transcript agents (Save-it first) push documents into a project over Kanboard's JSON-RPC API
(`jsonrpc.php`). A document is keyed by `(project_id, source, external_id)`, so pushing it again
updates it in place.

| Procedure | Params | Returns |
|---|---|---|
| `knowledgeUpsert` | `project_id`, `source` (≤ 64), `external_id` (≤ 191), `title` (≤ 255), `body` (≤ 200 000 characters, Markdown), `url` (http/https, optional), `author` (≤ 255, optional), `occurred_at` (unix time, optional), `metadata` (JSON object, ≤ 16 KB, optional) | `{"ok": true, "id": <id>}` |
| `knowledgeDelete` | `project_id`, `source`, `external_id` | `true` if a document was deleted |

```bash
curl -s -u saveit:<personal-api-token> https://kanboard.example.com/jsonrpc.php -d '{
  "jsonrpc": "2.0", "id": 1, "method": "knowledgeUpsert",
  "params": {
    "project_id": 3, "source": "save-it", "external_id": "ig-3141592653",
    "title": "Interview: pricing the spring launch",
    "body": "## Transcript\n\nWe settled on two tiers...",
    "url": "https://www.instagram.com/p/abc123/", "author": "@creator",
    "occurred_at": 1763510400, "metadata": {"duration_s": 412}
  }
}'
# {"jsonrpc":"2.0","result":{"ok":true,"id":17},"id":1}
```

Who may call them:

- **A user** (username with password or personal API token): a manager of the project, or an app
  admin. Anyone else, an inactive user, or a project that does not exist gets `403 Forbidden`. A
  dedicated Kanboard user with the manager role on the archive project is the intended caller.
- **The application token** (user `jsonrpc`): Kanboard gives it full API access, so it may write
  into any existing project. Keep it for trusted automation.

Invalid params come back as `-32602 Invalid params` with a plain reason in `data`.

Each document has a read-only page at `/agentchat/knowledge/<id>`, open to anyone who can view its
project; the bot links its citations there. Retrieved text reaches the model fenced as untrusted data,
never as instructions.

Knowledge search uses each database's own full-text engine, so results can differ by dialect: SQLite
FTS5 (`unicode61` tokens), MySQL/MariaDB InnoDB FULLTEXT (words under 3 characters and its stopwords
are not indexed) and Postgres `simple` text search (no stemming). Queries are reduced to their words
(no search syntax reaches the engine); SQLite and MySQL match any of the words, ranked, while Postgres
(`plainto_tsquery`) needs all of them.

To try it on a dev stack, push the sample transcripts as a project manager (from a checkout; the
fixture is not in the release zip):

```bash
KANBOARD_URL=http://localhost:8081/jsonrpc.php KANBOARD_USER=<manager> KANBOARD_TOKEN=<token> \
  php scripts/import-saveit-fixture.php <project_id> [Test/fixtures/saveit-sample.json]
```

## Chat API

Agents (and people) post to and read a project's room over JSON-RPC as themselves.

| Procedure | Params | Returns |
|---|---|---|
| `chatSendMessage` | `project_id`, `body` (1–8 000 characters after trimming, ≤ 64 KiB raw), `client_msg_id` (optional, 1–64 of `A-Za-z0-9_-`; resending it returns the same id) | `{"message_id": <id>}`, plus `bot_message_id` (and `bot_error`) when the body asks `@bot` |
| `chatRoomMessages` | `project_id`, `after` (message id, default 0), `limit` (1–100, default 50) | `[{"id", "sender_kind", "sender_name", "text", "created_at"}]`, oldest first |

Both need a user's API credentials (username with password or personal token). Posting needs the
member or manager role (or app admin), reading any project role. Anyone else, an inactive user or
an unknown project gets `403 Forbidden`; the application token gets `403` with `data` saying a
user token is required. `text` and `sender_name` are plain text. Private drawer messages never
appear.

A `chatSendMessage` body that mentions `@bot`, from a user who may ask while the bot is on, is
answered within the same request (no stream, up to the time limit); `bot_message_id` names the
answer. Resending the same `client_msg_id` returns the same answer and never starts a second run.
Over a limit, the message is still posted, `bot_message_id` is null and `bot_error` says why.

A page of `chatRoomMessages` ends just before the first bot answer still being written, so a
reader that pages with `after` never skips an answer; it sees nothing newer until that answer is
final (done, stopped or error). The wait is bounded: a crashed or stuck bot run is finalised as an
error within the run time limit plus a grace period.

## Without AiConnector

The bot needs AiConnector `1.2.0` or later with a configured provider. Without it (absent, too old,
or no provider ready), or with the bot switched off for a project:

- **The bot UI is gone**: no `@bot` in the placeholder or the mention picker, no "Ask the bot" button
  on tasks and no context ring. When AiConnector is the reason, project managers see a notice on the
  room and on the project's Chat settings saying so.
- **People chat keeps working.** Send posts a plain message, `@bot` included, and starts no answer;
  images still attach for people. The context ring's endpoint answers `204` (no ring), and Stop has
  nothing to stop.
- **Only `/agentchat/stream` answers `403`**, and only when AiConnector is absent or too old; no page
  links to it then. With AiConnector installed but no provider ready, or the bot off, a stream that
  is still opened ends at once (an empty event stream, so the browser does not reconnect) and the
  answer is marked as an error.

Agents can still post and read through the Chat API; a `@bot` in their message is just text. With
JavaScript off, the room and drawer still work as plain forms: messages post, and a bot answer is
written before the page reloads.

## Data

| Table | Holds |
|---|---|
| `agentchat_rooms` | one `project` room per project |
| `agentchat_messages` | room and private (task drawer) messages; the autoincrement id is the poll cursor |
| `agentchat_runs` | bot runs: hashed single-use stream id, status, stop flag, usage |
| `agentchat_files` | uploaded images, stored through Kanboard's object storage |
| `agentchat_knowledge` | searchable project knowledge, with full-text search (SQLite FTS5, MySQL FULLTEXT, Postgres tsvector) |

Deleting a project deletes its room, messages, runs, files (rows and stored images) and knowledge.

**MariaDB** uses the MySQL schema; InnoDB `FULLTEXT` works there as on MySQL. Postgres needs version 12
or later.

## chatparts

The UI kit lives in `vendor-chatparts/`, a hash-checked copy of the chatparts kit. Do not edit it by
hand; refresh it with:

```bash
scripts/sync-chatparts.sh ~/Projects/chatparts
```

The copy is verified against `vendor-chatparts/CHECKSUMS`, and `Test/ChatPartsVendorTest.php`
re-checks it.

## Development

```bash
# from the suite harness root (kanboard-plugins/)
./testing/run-plugin-tests.sh AgentChat
./scripts/package.sh AgentChat dist
```

## License

MIT. chatparts' own license and third-party notices ship in `vendor-chatparts/`.
