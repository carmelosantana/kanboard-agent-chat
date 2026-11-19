/*
 * chatparts client behaviour. Loads after htmx and htmx-ext-sse, on every page of the host, so
 * everything here is delegated from `document` and does nothing until a chatparts element is
 * involved. No eval, no Function constructor, no inline handlers: it runs under a strict CSP
 * with htmx's `allowEval: false`.
 *
 * The pure helpers at the top are exported for the Node tests (tests/js/*.test.mjs).
 *
 * Optional translations: <meta name="chatparts-i18n" content='{"Stop":"…","%s answered":"…"}'>,
 * keyed by the English strings in STR below.
 */
(function () {
  'use strict';

  var MAX_IMAGES = 4;
  var MAX_BYTES = 5 * 1024 * 1024;
  var IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

  // ---- Pure helpers ---------------------------------------------------------------------

  /** Textarea rows for a draft: one per line, long lines wrapping every `wrap` chars, capped. */
  function rowsFor(text, maxRows, wrap) {
    maxRows = maxRows || 10;
    wrap = wrap || 90;
    var rows = String(text).split('\n').reduce(function (n, line) {
      return n + Math.max(1, Math.ceil(line.length / wrap));
    }, 0);
    return Math.min(maxRows, Math.max(1, rows));
  }

  /** Enter sends; Shift+Enter is a newline; nothing while an IME is composing or when disabled. */
  function shouldSubmit(e, disabled) {
    return e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229 && !disabled;
  }

  /** Whether a scroll box is within `threshold` px of its bottom. */
  function isNearBottom(scrollTop, clientHeight, scrollHeight, threshold) {
    if (threshold === undefined) threshold = 48;
    return scrollHeight - scrollTop - clientHeight <= threshold;
  }

  /**
   * The attachment list after an action, never mutating `list`.
   * action: {type: 'add', item: {id, name, size, type}} | {type: 'removeLast'} | {type: 'remove', id}
   * returns {list, error}; error is null, 'type', 'too-large' or 'too-many' (list unchanged).
   */
  function nextAttachments(list, action) {
    switch (action.type) {
      case 'add': {
        var item = action.item;
        if (IMAGE_TYPES.indexOf(item.type) < 0) return { list: list, error: 'type' };
        if (item.size > MAX_BYTES) return { list: list, error: 'too-large' };
        if (list.length >= MAX_IMAGES) return { list: list, error: 'too-many' };
        return { list: list.concat([item]), error: null };
      }
      case 'removeLast':
        return { list: list.slice(0, -1), error: null };
      case 'remove':
        return { list: list.filter(function (x) { return x.id !== action.id; }), error: null };
      default:
        return { list: list, error: null };
    }
  }

  /** The `@word` being typed at the caret, as {start, query}, or null. */
  function mentionQuery(text, caret) {
    var m = /(^|\s)@([\w.-]*)$/.exec(String(text).slice(0, caret));
    return m ? { start: m.index + m[1].length, query: m[2] } : null;
  }

  /** The largest all-digits id, as a number, or null when there is none. */
  function maxMessageId(ids) {
    var best = null;
    for (var i = 0; i < ids.length; i++) {
      var id = ids[i];
      if (typeof id !== 'string' || !/^\d+$/.test(id)) continue;
      var n = Number(id);
      if (Number.isSafeInteger(n) && (best === null || n > best)) best = n;
    }
    return best;
  }

  /** Whether a (non-empty) id is already among `existing` (a Set or an array). */
  function isDuplicate(id, existing) {
    if (!id) return false;
    return typeof existing.has === 'function' ? existing.has(id) : existing.indexOf(id) >= 0;
  }

  /**
   * Whether a bubble {state, failed} will not change again: the server rendered it done, stopped
   * or error. A draft the client marked failed itself (after a stream error) is not final: the
   * stored copy, whatever it says, is still to come.
   */
  function isFinal(m) {
    return !m.failed && (m.state === 'done' || m.state === 'stopped' || m.state === 'error');
  }

  /**
   * The poll's `after` value from the bubbles shown, [{id, state, failed}]: the largest final id,
   * but never past the oldest unfinished one (so its stored copy is fetched again). Null when no
   * bubble has a numeric id, meaning keep the server-rendered value.
   */
  function pollCursor(list) {
    var maxFinal = maxMessageId(list.filter(isFinal).map(function (m) { return m.id; }));
    var minOpen = null;
    list.forEach(function (m) {
      var n = maxMessageId([m.id]);
      if (n !== null && !isFinal(m) && (minOpen === null || n < minOpen)) minOpen = n;
    });
    if (minOpen === null) return maxFinal;
    return maxFinal === null ? minOpen - 1 : Math.min(maxFinal, minOpen - 1);
  }

  /**
   * For a bubble {state, failed, hasStream} whose id is already shown: 'replace' when only the
   * newcomer is final, or when neither is final and only the newcomer carries a stream (the
   * asker's own copy, which a poll may have beaten to the page); else 'drop'.
   */
  function dedupeAction(existing, incoming) {
    if (isFinal(existing)) return 'drop';
    if (isFinal(incoming)) return 'replace';
    return incoming.hasStream && !existing.hasStream ? 'replace' : 'drop';
  }

  /** Client-error statuses whose kit Notice is shown (htmx 2 swaps no 4xx response by default). */
  var NOTICE_STATUSES = [400, 403, 409, 413, 422, 429];

  /** Whether an error response is a kit Notice to swap in: one of NOTICE_STATUSES and a cp-notice. */
  function shouldSwapError(status, text) {
    return NOTICE_STATUSES.indexOf(status) >= 0 && typeof text === 'string' && text.indexOf('class="cp-notice') >= 0;
  }

  /** Whether any streaming message in [{id, state}] is one this composer posted (`ownIds`, a Set). */
  function hasOwnStreaming(ownIds, list) {
    if (!ownIds) return false;
    return list.some(function (m) { return m.state === 'streaming' && ownIds.has(m.id); });
  }

  /** `path` with query parameter `key` set to `value` (replaced if present, else appended). */
  function setQueryParam(path, key, value) {
    var hashAt = path.indexOf('#');
    var hash = hashAt < 0 ? '' : path.slice(hashAt);
    var base = hashAt < 0 ? path : path.slice(0, hashAt);
    var q = base.indexOf('?');
    var head = q < 0 ? base : base.slice(0, q);
    var pairs = q < 0 || q === base.length - 1 ? [] : base.slice(q + 1).split('&');
    var pair = encodeURIComponent(key) + '=' + encodeURIComponent(String(value));
    var found = false;
    pairs = pairs.map(function (p) {
      var k = p.split('=')[0];
      try { k = decodeURIComponent(k); } catch (err) { /* keep it raw */ }
      if (k !== key) return p;
      found = true;
      return pair;
    });
    if (!found) pairs.push(pair);
    return head + '?' + pairs.join('&') + hash;
  }

  /** Whether a draft has no text to send (attachments do not count). */
  function isBlank(text) {
    return String(text).trim() === '';
  }

  /**
   * The name to announce for an SSE frame {type, id, name}: the answering participant's name for a
   * `final` frame whose message id is not in `announced` (a Set), else ''. The caller adds the id.
   */
  function answerToAnnounce(frame, announced) {
    if (frame.type !== 'final' || !frame.id || !frame.name || announced.has(frame.id)) return '';
    return frame.name;
  }

  /** Whether the context ring refetches after the attachment count went from `before` to `after`. */
  function ringNeedsRefresh(before, after) {
    return before !== after;
  }

  /** `s` with its first `%s` replaced by `arg`, taken literally. */
  function format(s, arg) {
    return arg === undefined ? s : s.replace('%s', function () { return String(arg); });
  }

  var api = {
    rowsFor: rowsFor,
    shouldSubmit: shouldSubmit,
    isNearBottom: isNearBottom,
    nextAttachments: nextAttachments,
    mentionQuery: mentionQuery,
    maxMessageId: maxMessageId,
    isDuplicate: isDuplicate,
    isFinal: isFinal,
    pollCursor: pollCursor,
    dedupeAction: dedupeAction,
    shouldSwapError: shouldSwapError,
    format: format,
    isBlank: isBlank,
    hasOwnStreaming: hasOwnStreaming,
    setQueryParam: setQueryParam,
    answerToAnnounce: answerToAnnounce,
    ringNeedsRefresh: ringNeedsRefresh,
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (typeof document === 'undefined' || typeof window === 'undefined') return;

  // ---- Strings --------------------------------------------------------------------------

  var STR = {};
  (function () {
    var meta = document.querySelector('meta[name="chatparts-i18n"]');
    if (!meta) return;
    try {
      var parsed = JSON.parse(meta.getAttribute('content') || '{}');
      if (parsed && typeof parsed === 'object') STR = parsed;
    } catch (err) { /* ignore a malformed meta */ }
  })();

  function t(s, arg) {
    return format(typeof STR[s] === 'string' ? STR[s] : s, arg);
  }

  var ERRORS = {
    'type': '%s is not a PNG, JPEG, WebP or GIF image.',
    'too-large': '%s is larger than 5 MB.',
    'too-many': 'Up to 4 images per message.',
  };

  // ---- Small DOM helpers ----------------------------------------------------------------

  function conversation() { return document.getElementById('cp-conversation'); }

  var STREAM_SEL = '[sse-connect], [data-sse-connect]';

  /** The bubbles under `root` that carry an id, as [{id, state, failed, hasStream, el}] in document order. */
  function messagesIn(root) {
    return Array.prototype.map.call(root.querySelectorAll('.cp-msg[data-id]'), function (el) {
      return {
        id: el.getAttribute('data-id'),
        state: el.getAttribute('data-state'),
        failed: el.hasAttribute('data-cp-failed'),
        hasStream: el.matches(STREAM_SEL) || !!el.querySelector(STREAM_SEL),
        el: el,
      };
    });
  }
  function eltOf(e) { return (e.detail && e.detail.elt) || e.target; }
  function matches(el, sel) { return !!(el && el.nodeType === 1 && el.matches(sel)); }

  function announce(text) {
    var region = document.getElementById('cp-announce');
    if (!region || !text) return;
    region.textContent = '';
    setTimeout(function () { region.textContent = text; }, 50);
  }

  /** The sprite URL the server-rendered icons use, so script-made icons match them. */
  function spriteFrom(scope) {
    var use = scope && scope.querySelector('use');
    var href = use && (use.getAttribute('href') || use.getAttribute('xlink:href'));
    return href ? href.split('#')[0] : null;
  }

  function setIcon(btn, name) {
    var use = btn.querySelector('use');
    if (!use) return;
    var href = use.getAttribute('href') || '';
    use.setAttribute('href', href.split('#')[0] + '#' + name);
  }

  function icon(sprite, name) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('class', 'cp-icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var use = document.createElementNS(ns, 'use');
    use.setAttribute('href', sprite + '#' + name);
    svg.appendChild(use);
    return svg;
  }

  function randomHex(bytes) {
    var a = new Uint8Array(bytes);
    window.crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
  }

  // ---- Composer: attachments ------------------------------------------------------------

  var attachments = new WeakMap(); // form -> {list, files: Map(id -> File), urls: Map(id -> data URL)}
  var nextId = 1;

  function stateOf(form) {
    var s = attachments.get(form);
    if (!s) {
      s = { list: [], files: new Map(), urls: new Map() };
      attachments.set(form, s);
    }
    return s;
  }

  function fileInput(form) { return form.querySelector('input[type="file"]'); }

  function syncFileInput(form, s) {
    var input = fileInput(form);
    if (!input || typeof DataTransfer === 'undefined') return;
    var dt = new DataTransfer();
    s.list.forEach(function (item) { dt.items.add(s.files.get(item.id)); });
    input.files = dt.files;
  }

  function renderThumbs(form, s) {
    var box = form.querySelector('.cp-thumbs');
    if (!box) return;
    while (box.firstChild) box.removeChild(box.firstChild);
    var sprite = spriteFrom(form);
    s.list.forEach(function (item) {
      var thumb = document.createElement('div');
      thumb.className = 'cp-thumb';
      thumb.setAttribute('data-id', item.id);
      var img = document.createElement('img');
      img.className = 'cp-thumb__img';
      img.alt = item.name;
      var url = s.urls.get(item.id);
      if (url) img.src = url;
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'cp-thumb__x';
      x.setAttribute('data-action', 'remove-image');
      x.setAttribute('data-id', item.id);
      x.setAttribute('aria-label', t('Remove %s', item.name));
      if (sprite) x.appendChild(icon(sprite, 'x')); else x.textContent = '×';
      thumb.appendChild(img);
      thumb.appendChild(x);
      box.appendChild(thumb);
    });
    updateEmpty(form);
  }

  /**
   * Replace the attachment list. A changed count fires `cp-attachments` on the form, which the
   * context ring's hx-trigger listens for (sharing its typing debounce); its request then sends
   * the new `images` count.
   */
  function setAttachments(form, s, list) {
    var before = s.list.length;
    s.list = list;
    var keep = new Set(list.map(function (x) { return x.id; }));
    s.files.forEach(function (_f, id) { if (!keep.has(id)) { s.files.delete(id); s.urls.delete(id); } });
    syncFileInput(form, s);
    renderThumbs(form, s);
    if (ringNeedsRefresh(before, list.length)) form.dispatchEvent(new CustomEvent('cp-attachments', { bubbles: true }));
  }

  function addFiles(form, files) {
    if (!fileInput(form)) return;
    var s = stateOf(form);
    var list = s.list;
    Array.prototype.forEach.call(files, function (file) {
      var id = 'a' + nextId++;
      var r = nextAttachments(list, { type: 'add', item: { id: id, name: file.name, size: file.size, type: file.type } });
      if (r.error) {
        announce(t(ERRORS[r.error], file.name));
        return;
      }
      list = r.list;
      s.files.set(id, file);
      // A data: URL, not a blob: one: a CSP of `img-src * data:` is common, and `*` does not cover blob:.
      var reader = new FileReader();
      reader.onload = function () {
        if (!s.files.has(id)) return;
        s.urls.set(id, String(reader.result));
        var img = form.querySelector('.cp-thumb[data-id="' + id + '"] img');
        if (img) img.src = String(reader.result);
      };
      reader.readAsDataURL(file);
    });
    setAttachments(form, s, list);
  }

  function imagesOnly(files) {
    return Array.prototype.filter.call(files || [], function (f) { return /^image\//.test(f.type); });
  }

  // ---- Composer: state ------------------------------------------------------------------

  function textareaOf(form) { return form.querySelector('.cp-box__input'); }

  function updateEmpty(form) {
    var ta = textareaOf(form);
    // Text is mandatory (1–8000 characters after trim); images alone are not a message.
    form.toggleAttribute('data-empty', !ta || isBlank(ta.value));
  }

  function isStopMode(form) {
    var btn = form.querySelector('.cp-send');
    return !!btn && btn.getAttribute('data-state') !== 'ready';
  }

  /** Message ids each composer got back from its own posts: form -> Set of ids. */
  var ownIds = new WeakMap();

  /** Remember the bubbles in a composer's post response as that composer's own. */
  function recordOwn(form, html) {
    if (typeof DOMParser === 'undefined' || typeof html !== 'string' || html === '') return;
    var doc = new DOMParser().parseFromString(html, 'text/html'); // inert: scripts never run
    var ids = ownIds.get(form) || new Set();
    messagesIn(doc).forEach(function (m) { if (m.id) ids.add(m.id); });
    ownIds.set(form, ids);
  }

  /**
   * Send becomes Stop while a reply this composer asked for is streaming (only where the host
   * gave a stop URL); someone else's streaming reply leaves it alone.
   */
  function syncSend() {
    var list = messagesIn(document);
    document.querySelectorAll('.cp-send[data-stop-url]').forEach(function (btn) {
      var streaming = hasOwnStreaming(btn.form && ownIds.get(btn.form), list);
      var cur = btn.getAttribute('data-state');
      var next = streaming ? (cur === 'stopping' ? 'stopping' : 'streaming') : 'ready';
      if (cur === next) return;
      btn.setAttribute('data-state', next);
      btn.setAttribute('aria-label', t(streaming ? 'Stop' : 'Send'));
      setIcon(btn, streaming ? 'square' : 'arrow-up');
    });
  }

  function requestStop(form, btn) {
    var url = btn.getAttribute('data-stop-url');
    if (!url || btn.getAttribute('data-state') === 'stopping') return;
    btn.setAttribute('data-state', 'stopping');
    var body = new FormData();
    form.querySelectorAll('input[type="hidden"][name]').forEach(function (input) { body.append(input.name, input.value); });
    var revert = function () {
      if (btn.getAttribute('data-state') === 'stopping') btn.setAttribute('data-state', 'streaming');
    };
    window.fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'HX-Request': 'true' } })
      .then(function (res) { if (!res.ok) revert(); }, revert);
  }

  function resetComposer(form) {
    var ta = textareaOf(form);
    if (ta) {
      ta.value = '';
      ta.rows = 1;
    }
    var id = form.querySelector('input[name="client_msg_id"]');
    if (id && window.crypto) id.value = randomHex(16);
    var s = attachments.get(form);
    if (s) setAttachments(form, s, []);
    updateEmpty(form);
  }

  // ---- Mention picker -------------------------------------------------------------------

  var mention = { ta: null };

  function picker() { return document.getElementById('cp-mention'); }

  function visibleItems(p) {
    return Array.prototype.filter.call(p.querySelectorAll('.cp-mention__item'), function (li) { return !li.hidden; });
  }

  function setActive(p, li) {
    p.querySelectorAll('.cp-mention__item').forEach(function (x) { x.setAttribute('aria-selected', x === li ? 'true' : 'false'); });
    if (mention.ta) {
      if (li) mention.ta.setAttribute('aria-activedescendant', li.id);
      else mention.ta.removeAttribute('aria-activedescendant');
    }
  }

  function closeMention() {
    var p = picker();
    if (p) p.hidden = true;
    if (mention.ta) mention.ta.removeAttribute('aria-activedescendant');
    mention.ta = null;
  }

  function updateMention(ta) {
    var p = picker();
    if (!p) return;
    var q = mentionQuery(ta.value, ta.selectionStart);
    if (!q) { closeMention(); return; }
    var needle = q.query.toLowerCase();
    p.querySelectorAll('.cp-mention__item').forEach(function (li) {
      var handle = (li.getAttribute('data-handle') || '').toLowerCase();
      var name = li.textContent.toLowerCase();
      li.hidden = !(handle.indexOf(needle) === 0 || name.indexOf(needle) >= 0);
    });
    var items = visibleItems(p);
    if (!items.length) { closeMention(); return; }
    mention.ta = ta;
    ta.setAttribute('aria-controls', p.id);
    p.hidden = false;
    setActive(p, items[0]);
  }

  function pickMention(li) {
    var ta = mention.ta;
    if (!ta || !li) return;
    var caret = ta.selectionStart;
    var q = mentionQuery(ta.value, caret);
    if (!q) { closeMention(); return; }
    var insert = '@' + li.getAttribute('data-handle') + ' ';
    ta.value = ta.value.slice(0, q.start) + insert + ta.value.slice(caret);
    var pos = q.start + insert.length;
    ta.setSelectionRange(pos, pos);
    closeMention();
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  /** Arrow keys, Enter/Tab and Escape while the picker is open; true when the key was used. */
  function mentionKey(e) {
    var p = picker();
    if (!p || p.hidden || mention.ta !== e.target) return false;
    var items = visibleItems(p);
    var cur = items.indexOf(p.querySelector('.cp-mention__item[aria-selected="true"]'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      var step = e.key === 'ArrowDown' ? 1 : -1;
      setActive(p, items[(cur + step + items.length) % items.length]);
    } else if ((e.key === 'Enter' && !e.isComposing && e.keyCode !== 229) || e.key === 'Tab') {
      pickMention(items[cur] || items[0]);
    } else if (e.key === 'Escape') {
      closeMention();
    } else {
      return false;
    }
    e.preventDefault();
    return true;
  }

  // ---- Conversation: stick to bottom ----------------------------------------------------

  var scroll = { stuck: true, height: 0 };

  function reducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function toBottom(conv, smooth) {
    conv.scrollTo({ top: conv.scrollHeight, behavior: smooth && !reducedMotion() ? 'smooth' : 'auto' });
    scroll.stuck = true;
    var jump = document.getElementById('cp-jump');
    if (jump) jump.hidden = true;
  }

  /** After content changes: follow the bottom if the reader was there, else offer the jump pill. */
  function afterChange() {
    var conv = conversation();
    if (!conv) return;
    var grew = conv.scrollHeight > scroll.height;
    if (scroll.stuck) {
      toBottom(conv, false);
    } else if (grew) {
      var jump = document.getElementById('cp-jump');
      if (jump) jump.hidden = false;
    }
    scroll.height = conv.scrollHeight;
  }

  /**
   * One bubble per data-id (a message can come by the send response, the poll and the stream):
   * a later copy replaces the shown one in place when only the later copy is finished, or when
   * neither is and only the later copy carries the stream; it is dropped otherwise.
   */
  function dedupe(conv) {
    var shown = new Map();
    messagesIn(conv).forEach(function (m) {
      if (!m.id) return;
      if (!isDuplicate(m.id, shown)) {
        shown.set(m.id, m);
        return;
      }
      var prev = shown.get(m.id);
      if (dedupeAction(prev, m) === 'replace') {
        prev.el.parentNode.insertBefore(m.el, prev.el);
        discard(prev.el);
        shown.set(m.id, m);
      } else {
        discard(m.el);
      }
    });
  }

  // ---- Streams --------------------------------------------------------------------------

  /**
   * Remove a bubble and close any stream inside it. htmx-ext-sse closes its EventSource on
   * htmx:beforeCleanupElement, which a plain remove() never sends; htmx.trigger reaches the
   * extension, and dropping sse-connect stops its retry timer.
   */
  function discard(el) {
    var streams = Array.prototype.slice.call(el.querySelectorAll(STREAM_SEL));
    if (matches(el, STREAM_SEL)) streams.push(el);
    streams.forEach(function (node) {
      if (window.htmx && typeof window.htmx.trigger === 'function') window.htmx.trigger(node, 'htmx:beforeCleanupElement');
      forgetStream(node);
    });
    el.remove();
  }

  function forgetStream(el) {
    if (!el || !el.removeAttribute) return;
    el.removeAttribute('sse-connect');
    el.removeAttribute('data-sse-connect');
  }

  /** A stream that failed leaves its draft bubbles in `error`, so the composer unlocks. */
  function failDrafts(el) {
    if (!el || !el.querySelectorAll) return;
    var drafts = Array.prototype.slice.call(el.querySelectorAll('.cp-msg[data-state="streaming"]'));
    if (matches(el, '.cp-msg[data-state="streaming"]')) drafts.push(el);
    drafts.forEach(function (msg) {
      msg.setAttribute('data-state', 'error');
      msg.setAttribute('data-cp-failed', '');
      msg.removeAttribute('aria-busy');
      msg.querySelectorAll('.cp-caret').forEach(function (c) { c.classList.remove('cp-caret'); });
    });
  }

  /** The {id, name} of the bubble in a final frame's HTML, read as text; empty strings when absent. */
  function answeredBy(html) {
    if (typeof DOMParser === 'undefined' || typeof html !== 'string') return { id: '', name: '' };
    var doc = new DOMParser().parseFromString(html, 'text/html'); // inert: scripts never run
    var msg = doc.querySelector('.cp-msg[data-id]');
    var name = doc.querySelector('.cp-msg__name');
    return { id: msg ? msg.getAttribute('data-id') : '', name: name ? name.textContent.trim() : '' };
  }

  /** Message ids whose finished answer was announced, so a repeated final frame stays quiet. */
  var announced = new Set();

  // ---- Wiring ---------------------------------------------------------------------------

  function init(root) {
    if (!root || !root.querySelectorAll) return;
    var forms = Array.prototype.slice.call(root.querySelectorAll('form.cp-box'));
    if (matches(root, 'form.cp-box')) forms.push(root);
    forms.forEach(function (form) {
      var ta = textareaOf(form);
      if (ta) ta.rows = rowsFor(ta.value);
      updateEmpty(form);
    });
    var conv = conversation();
    if (conv && (root === conv || root.contains(conv))) {
      scroll.stuck = true;
      scroll.height = 0;
    }
    syncSend();
    afterChange();
  }

  document.addEventListener('input', function (e) {
    var ta = e.target;
    if (!matches(ta, '.cp-box__input')) return;
    ta.rows = rowsFor(ta.value);
    updateEmpty(ta.form);
    updateMention(ta);
  });

  document.addEventListener('keydown', function (e) {
    var ta = e.target;
    if (matches(ta, '.cp-box__input')) {
      if (mentionKey(e)) return;
      var form = ta.form;
      if (shouldSubmit(e, !form || isStopMode(form))) {
        e.preventDefault();
        if (!form.hasAttribute('data-empty')) form.requestSubmit();
        return;
      }
      if (e.key === 'Backspace' && ta.value === '' && form) {
        var s = attachments.get(form);
        if (s && s.list.length) {
          e.preventDefault();
          setAttachments(form, s, nextAttachments(s.list, { type: 'removeLast' }).list);
        }
      }
      return;
    }
    if (e.key !== 'Escape') return;
    var pop = document.querySelector('.cp-context__pop:not([hidden])');
    if (pop) {
      toggleContext(document.querySelector('[aria-controls="' + pop.id + '"]'), false);
      return;
    }
    var drawer = e.target.closest && e.target.closest('.cp-drawer');
    if (drawer) closeDrawer(drawer);
  });

  document.addEventListener('focusout', function (e) {
    if (mention.ta && e.target === mention.ta) closeMention();
  });

  document.addEventListener('mousedown', function (e) {
    var li = e.target.closest && e.target.closest('.cp-mention__item');
    if (!li || !mention.ta) return;
    e.preventDefault(); // keep focus in the textarea
    pickMention(li);
  });

  document.addEventListener('paste', function (e) {
    if (!matches(e.target, '.cp-box__input') || !e.clipboardData) return;
    var form = e.target.form;
    var files = imagesOnly(e.clipboardData.files);
    if (!form || !files.length || !fileInput(form)) return;
    if (Array.prototype.indexOf.call(e.clipboardData.types || [], 'text/plain') < 0) e.preventDefault();
    addFiles(form, files);
  });

  document.addEventListener('dragover', function (e) {
    var form = e.target.closest && e.target.closest('form.cp-box');
    if (!form || !fileInput(form) || !e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') < 0) return;
    e.preventDefault();
    form.classList.add('cp-box--drop');
  });

  document.addEventListener('dragleave', function (e) {
    var form = e.target.closest && e.target.closest('form.cp-box');
    if (form && !form.contains(e.relatedTarget)) form.classList.remove('cp-box--drop');
  });

  document.addEventListener('drop', function (e) {
    var form = e.target.closest && e.target.closest('form.cp-box');
    if (!form || !fileInput(form) || !e.dataTransfer) return;
    e.preventDefault();
    form.classList.remove('cp-box--drop');
    addFiles(form, imagesOnly(e.dataTransfer.files));
  });

  document.addEventListener('change', function (e) {
    var input = e.target;
    if (!matches(input, 'form.cp-box input[type="file"]')) return;
    var form = input.form;
    var picked = Array.prototype.slice.call(input.files || []);
    syncFileInput(form, stateOf(form)); // the dialog replaced the list; put ours back, then add
    addFiles(form, picked);
  });

  // Capture phase: runs before htmx's own submit listener on the form.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!matches(form, 'form.cp-box')) return;
    if (isStopMode(form) || form.hasAttribute('data-empty')) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);

  document.addEventListener('click', function (e) {
    var target = e.target.closest ? e.target.closest('button, a') : null;
    if (!target) {
      closeContextOutside(e.target);
      return;
    }
    closeContextOutside(target);

    if (matches(target, '.cp-send') && isStopMode(target.form)) {
      e.preventDefault();
      requestStop(target.form, target);
    } else if (matches(target, '[data-action="attach"]')) {
      var input = document.getElementById(target.getAttribute('aria-controls'));
      if (input) input.click();
    } else if (matches(target, '[data-action="remove-image"]')) {
      var form = target.closest('form.cp-box');
      var s = form && attachments.get(form);
      if (s) {
        setAttachments(form, s, nextAttachments(s.list, { type: 'remove', id: target.getAttribute('data-id') }).list);
        var ta = textareaOf(form);
        if (ta) ta.focus();
      }
    } else if (matches(target, '.cp-ctl--context')) {
      var pop = document.getElementById(target.getAttribute('aria-controls'));
      toggleContext(target, !!(pop && pop.hidden));
    } else if (matches(target, '.cp-msg [data-action="copy"]')) {
      copyMessage(target);
    } else if (matches(target, '#cp-jump')) {
      var conv = conversation();
      if (conv) toBottom(conv, true);
    } else if (matches(target, '.cp-drawer [data-action="close"]')) {
      closeDrawer(target.closest('.cp-drawer'));
    }
  });

  document.addEventListener('scroll', function (e) {
    var conv = conversation();
    if (e.target !== conv) return;
    scroll.stuck = isNearBottom(conv.scrollTop, conv.clientHeight, conv.scrollHeight);
    if (scroll.stuck) {
      var jump = document.getElementById('cp-jump');
      if (jump) jump.hidden = true;
    }
  }, true);

  function toggleContext(btn, open) {
    if (!btn) return;
    var pop = document.getElementById(btn.getAttribute('aria-controls'));
    if (!pop) return;
    pop.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function closeContextOutside(el) {
    var pop = document.querySelector('.cp-context__pop:not([hidden])');
    if (!pop || pop.contains(el)) return;
    var btn = document.querySelector('[aria-controls="' + pop.id + '"]');
    if (btn && btn.contains(el)) return;
    toggleContext(btn, false);
  }

  function copyMessage(btn) {
    var content = btn.closest('.cp-msg').querySelector('.cp-msg__content');
    if (!content || !navigator.clipboard) return;
    navigator.clipboard.writeText(content.innerText.trim()).then(function () {
      btn.setAttribute('data-copied', '');
      setIcon(btn, 'check');
      announce(t('Copied'));
      setTimeout(function () {
        btn.removeAttribute('data-copied');
        setIcon(btn, 'copy');
      }, 1500);
    }, function () { /* clipboard refused: leave the button as it was */ });
  }

  function closeDrawer(drawer) {
    if (!drawer) return;
    drawer.hidden = true;
    var opener = drawer.id ? document.querySelector('[aria-controls="' + drawer.id + '"]') : null;
    if (opener) {
      opener.setAttribute('aria-expanded', 'false');
      opener.focus();
    }
  }

  // ---- htmx events ----------------------------------------------------------------------

  document.addEventListener('htmx:configRequest', function (e) {
    var el = eltOf(e);
    var d = e.detail;
    if (matches(el, '[data-cp-poll]')) {
      var after = pollCursor(messagesIn(el));
      if (after !== null) d.path = setQueryParam(d.path, 'after', after);
    } else if (matches(el, '.cp-ctl--context')) {
      var form = el.closest('form');
      var ta = form && textareaOf(form);
      var s = form && attachments.get(form);
      d.parameters.draft_len = String(ta ? Array.from(ta.value).length : 0);
      d.parameters.images = String(s ? s.list.length : 0);
    }
  });

  // Polling pauses while the tab is hidden (a trigger filter would need eval).
  document.addEventListener('htmx:beforeRequest', function (e) {
    if (document.hidden && matches(eltOf(e), '[data-cp-poll]')) e.preventDefault();
  });

  // A kit Notice sent with a client-error status is shown in the request's target.
  document.addEventListener('htmx:beforeSwap', function (e) {
    var xhr = e.detail.xhr;
    if (!xhr || !shouldSwapError(xhr.status, xhr.responseText)) return;
    e.detail.shouldSwap = true;
    e.detail.isError = false;
  });

  document.addEventListener('htmx:afterRequest', function (e) {
    var el = eltOf(e);
    var xhr = e.detail.xhr;
    // A swapped error Notice reads as successful; the draft stays so it can be fixed and resent.
    if (matches(el, 'form.cp-box') && e.detail.successful && !(xhr && xhr.status >= 400)) {
      resetComposer(el);
      scroll.stuck = true;
    }
  });

  function onSwapped() {
    var conv = conversation();
    if (conv) dedupe(conv);
    syncSend();
    afterChange();
  }
  document.addEventListener('htmx:afterSwap', function (e) {
    // detail.elt is the swapped element here; the request's own element is in requestConfig.
    var src = e.detail.requestConfig && e.detail.requestConfig.elt;
    if (matches(src, 'form.cp-box') && e.detail.xhr) recordOwn(src, e.detail.xhr.responseText);
    onSwapped();
  });
  document.addEventListener('htmx:oobAfterSwap', onSwapped);

  // Announced before the swap: a final frame usually replaces the bubble that holds the stream,
  // so its htmx:sseMessage fires on a detached element and never reaches the document.
  document.addEventListener('htmx:sseBeforeMessage', function (e) {
    var msg = e.detail || {};
    if (msg.type !== 'final') return;
    var by = answeredBy(msg.data);
    var name = answerToAnnounce({ type: msg.type, id: by.id, name: by.name }, announced);
    if (!name) return;
    announced.add(by.id);
    announce(t('%s answered', name));
  });

  document.addEventListener('htmx:sseMessage', function (e) {
    var msg = e.detail || {};
    if (msg.type === 'done') {
      if (msg.target && typeof msg.target.close === 'function') msg.target.close();
      forgetStream(e.target.closest && e.target.closest(STREAM_SEL));
    }
    onSwapped();
  });

  // htmx-ext-sse reconnects forever on any error or closed source; one failure ends the stream.
  document.addEventListener('htmx:sseError', function (e) {
    var el = eltOf(e);
    forgetStream(el);
    var source = e.detail && e.detail.source;
    if (source && typeof source.close === 'function') source.close();
    failDrafts(el);
    syncSend();
  });

  document.addEventListener('htmx:sseClose', function (e) {
    forgetStream(eltOf(e));
  });

  if (window.htmx && typeof window.htmx.onLoad === 'function') {
    window.htmx.onLoad(init);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document.body); });
  } else {
    init(document.body);
  }
})();
