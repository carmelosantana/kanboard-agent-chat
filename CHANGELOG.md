# Changelog

## 0.1.0 — 2026-11-19

- First release: a Chat tab on every project, one room per project for people and agents, refreshed
  by a 4-second poll, with a People/Agents/Bots rail that moves behind a People button on phones.
- The project bot answers `@bot` through AiConnector (1.2.0, php-agents 0.17): streamed to the asker
  over server-sent events, with reasoning, a tool strip, numbered citations, a sources row and a
  receipt; Stop keeps the partial answer. Four read-only tools (`search_tasks`, `get_task`,
  `search_comments`, `search_knowledge`) that read as the asker, inside the room's project.
- "Ask the bot" drawer on the task page: a private thread per person and task, answered task-first,
  with Share to room (once, images copied). Viewers ask there (text only) when the project lets
  everyone ask.
- Composer from the chatparts kit (vendored, hash-checked): growing box, images by button, paste or
  drop (up to 4, 5 MB each), context ring with a breakdown, Send that turns into Stop, and "The bot
  won't see images" when images stay with people only.
- Project knowledge: `knowledgeUpsert` / `knowledgeDelete` over JSON-RPC for transcript agents, full-
  text search on SQLite, MySQL/MariaDB and Postgres, and a read-only page per document.
- Chat API for agents: `chatSendMessage` (a `@bot` body is answered in the same request) and
  `chatRoomMessages`.
- Project settings (bot on/off, who may ask, AI profile, send images to the bot) and instance limits
  (questions per hour, answers at once per person and per instance, time, steps, tool calls, answer
  size), with the PHP-FPM worker warning.
- Degrades without AiConnector: the bot UI is gone and people chat keeps working; works without
  JavaScript as plain forms.

### Known gaps

- The task drawer has no "This task / Whole project" chips. Its answers already search the whole
  project and only put the task first, so whole-project questions work; the chips come in 0.1.x.
- No online dots in the rail yet, so Presence is not recommended in this release.
