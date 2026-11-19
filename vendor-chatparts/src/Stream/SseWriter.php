<?php

declare(strict_types=1);

namespace ChatParts\Stream;

use Closure;
use InvalidArgumentException;

/**
 * Writes an HTML-over-SSE response for htmx-ext-sse: named events whose data is an HTML
 * fragment, comment heartbeats, and a final `done` that the client closes on.
 *
 * The host owns the request around it. Before open() it checks access and calls
 * session_write_close() (a stream lives for many seconds and must not hold the session
 * lock), and it lifts the time limit if it needs to; after close() it ends the request.
 * The kit does none of that.
 *
 * Every response the stream URL gives must end with `done`, including refusals: htmx-ext-sse
 * 2.2.4 reconnects whenever the source closes for any other reason (a 204 or a 403 included),
 * forever. Answer a spent, unknown or foreign stream id with refuse(), not an error status.
 *
 * The first write opens the stream if open() was not called, so even close() on an early
 * error path answers as an event stream. Once closed (by close() or refuse()) the writer stays
 * closed: later writes are no-ops, so an error path can call close() without checking whether
 * the stream already started or ended.
 */
final class SseWriter
{
    private const HEADERS = ['Content-Type: text/event-stream', 'Cache-Control: no-cache', 'X-Accel-Buffering: no'];

    private readonly Closure $out;
    private readonly Closure $clock;
    private readonly Closure $header;
    /** True when writing to PHP's own output, so open() must drain buffers and stop zlib. */
    private readonly bool $phpOutput;
    private bool $opened = false;
    private bool $closed = false;
    private float $lastWrite;

    /**
     * @param (callable(string): void)|null $out    receives each frame; defaults to echo + flush()
     * @param (callable(): float)|null      $clock  seconds; defaults to microtime(true)
     * @param (callable(string, bool, int): void)|null $header sends one response header, with
     *   header()'s replace flag and response code (0 = leave the status alone); defaults to header()
     */
    public function __construct(?callable $out = null, ?callable $clock = null, ?callable $header = null)
    {
        $this->phpOutput = $out === null;
        $this->out = $out === null
            ? static function (string $s): void {
                echo $s;
                flush();
            }
            : Closure::fromCallable($out);
        $this->clock = $clock === null ? static fn (): float => microtime(true) : Closure::fromCallable($clock);
        $this->header = $header === null ? static fn (string $h, bool $replace = true, int $code = 0) => header($h, $replace, $code) : Closure::fromCallable($header);
        $this->lastWrite = ($this->clock)();
    }

    /**
     * Sends the stream headers and, when writing to PHP's output, flushes every output buffer
     * and turns off zlib compression so each frame leaves at once. Idempotent.
     */
    public function open(): void
    {
        $this->start(0);
    }

    /** open() with a status code on the first header (0 leaves the status alone). */
    private function start(int $status): void
    {
        if ($this->opened) {
            return;
        }
        $this->opened = true;
        foreach (self::HEADERS as $i => $h) {
            ($this->header)($h, true, $i === 0 ? $status : 0);
        }
        if ($this->phpOutput) {
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0 && @ob_end_flush()) {
            }
        }
    }

    /**
     * One named event. The HTML's line breaks (LF, CRLF or CR) become one `data:` line per
     * line, so no line of it can start a new SSE field.
     *
     * @throws InvalidArgumentException for an empty name, or one containing CR, LF or ':'
     */
    public function event(string $name, string $html): void
    {
        if ($name === '' || strpbrk($name, "\r\n:") !== false) {
            throw new InvalidArgumentException('An SSE event name must be non-empty and contain no CR, LF or colon.');
        }
        if ($this->closed) {
            return;
        }
        $frame = "event: {$name}\n";
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $html)) as $line) {
            $frame .= "data: {$line}\n";
        }
        $this->write($frame . "\n");
    }

    /** A comment line: keeps proxies from closing a quiet stream; the client ignores it. */
    public function heartbeat(): void
    {
        if (!$this->closed) {
            $this->write(": hb\n\n");
        }
    }

    /** A heartbeat if nothing has been written for $everySeconds. */
    public function maybeHeartbeat(int $everySeconds = 15): void
    {
        if (($this->clock)() - $this->lastWrite >= $everySeconds) {
            $this->heartbeat();
        }
    }

    /** Ends the stream with one `done` event (empty data). Safe to call more than once. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->event(StreamEvents::DONE, '');
        $this->closed = true;
    }

    /**
     * The whole answer for a stream id that is spent, unknown or not the caller's: a 200
     * event stream holding only `done`, so the client closes instead of reconnecting. The 200
     * is set explicitly (a host may have set another status earlier) unless the stream is
     * already open, when headers are gone and only `done` is written.
     */
    public function refuse(): void
    {
        $this->start(200);
        $this->close();
    }

    private function write(string $s): void
    {
        $this->open();
        ($this->out)($s);
        $this->lastWrite = ($this->clock)();
    }
}
