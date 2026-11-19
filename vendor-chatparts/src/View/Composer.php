<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;

/**
 * The message box: a form that posts with htmx (appending the stored bubble to the
 * conversation) and still works as a plain form without JS. Inside: the host's CSRF field, a
 * fresh `client_msg_id` (the server's idempotency key; chatparts.js renews it after each send),
 * the textarea, and a controls bar with attach, the context ring and send. The ring button
 * fetches the ContextMeter on first click and again as the draft or its images change
 * (chatparts.js fires `cp-attachments` on the form, sharing the typing debounce); the send button
 * carries the stop URL so the script can turn it into a stop button while a reply streams.
 */
final class Composer extends Component
{
    private const IMAGE_TYPES = 'image/png,image/jpeg,image/webp,image/gif';

    /** A hint for the browser; the server's own limit is what counts. */
    private const MAX_LENGTH = 8000;

    /**
     * @param array{placeholder?: string, room?: string, allowImages?: bool, botSeesImages?: bool, canAsk?: bool, contextUrl?: string, stopUrl?: string} $opts
     *   `room` is posted as a hidden field; `canAsk` adds the @bot hint to the default
     *   placeholder; without `allowImages` there is no attach control or file input;
     *   `botSeesImages: false` adds "The bot won't see images" under the thumbnails (shown
     *   while there are any); without a safe `contextUrl` there is no ring
     */
    public function __construct(HostAdapter $host, private readonly string $action, private readonly array $opts = [])
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        $images = ($this->opts['allowImages'] ?? false) === true;
        $action = $this->safeUrl($this->action);
        $room = $this->str('room');

        $fields = $this->host->csrfField()
            . $this->tag('input', ['type' => 'hidden', 'name' => 'client_msg_id', 'value' => bin2hex(random_bytes(16))])
            . ($room === '' ? '' : $this->tag('input', ['type' => 'hidden', 'name' => 'room', 'value' => $room]));

        $box = ($images ? $this->tag('div', ['id' => 'cp-thumbs', 'class' => 'cp-thumbs']) . $this->imagesHint() : '')
            . $this->tag('label', ['class' => 'cp-sr', 'for' => 'cp-message'], $this->e($this->host->t('Message')))
            . $this->tag('textarea', ['id' => 'cp-message', 'class' => 'cp-box__input', 'name' => 'message', 'rows' => '1', 'maxlength' => (string) self::MAX_LENGTH, 'required' => '', 'placeholder' => $this->placeholder()])
            . ($images ? $this->tag('input', ['type' => 'file', 'id' => 'cp-images', 'name' => 'images[]', 'accept' => self::IMAGE_TYPES, 'multiple' => '', 'hidden' => '']) : '');

        $attach = $images ? $this->tag(
            'button',
            ['type' => 'button', 'class' => 'cp-ctl', 'data-action' => 'attach', 'aria-controls' => 'cp-images', 'aria-label' => $this->host->t('Add images')],
            (string) new Icon($this->host, 'plus'),
        ) : '';
        $send = $this->tag(
            'button',
            ['type' => 'submit', 'class' => 'cp-send', 'data-state' => 'ready', 'data-stop-url' => $this->safeUrl($this->str('stopUrl')), 'aria-label' => $this->host->t('Send')],
            (string) new Icon($this->host, 'arrow-up'),
        );
        $controls = $this->tag('div', ['class' => 'cp-controls'], $attach . $this->tag('span', ['class' => 'cp-controls__spacer']) . $this->context() . $send);

        return $this->tag(
            'form',
            ['class' => 'cp-box', 'method' => 'post', 'action' => $action, 'enctype' => $images ? 'multipart/form-data' : null]
                + ($action === null ? [] : Hx::attrs(['post' => $action, 'target' => '#cp-conversation', 'swap' => 'beforeend'])),
            $fields . $box . $controls,
        );
    }

    /**
     * The ring button and its (initially closed, empty) popover; nothing without a safe URL. The
     * button's name is its content (no aria-label), so once the meter is swapped in, it reads
     * "11% of context used".
     */
    private function context(): string
    {
        $url = $this->safeUrl($this->str('contextUrl'));
        if ($url === null) {
            return '';
        }

        return $this->tag(
            'button',
            ['type' => 'button', 'class' => 'cp-ctl cp-ctl--context']
                + Hx::attrs(['get' => $url, 'trigger' => 'input changed delay:500ms from:closest form, cp-attachments delay:500ms from:closest form, click once', 'target' => 'this', 'swap' => 'innerHTML'])
                + ['aria-controls' => 'cp-context-pop', 'aria-expanded' => 'false'],
            new Icon($this->host, 'gauge') . $this->tag('span', ['class' => 'cp-sr'], $this->e($this->host->t('Context used'))),
        ) . $this->tag('div', ['id' => 'cp-context-pop', 'class' => 'cp-context__pop', 'hidden' => '']);
    }

    /** People still see the images; only the bot does not, so the hint shows while thumbnails do (CSS). */
    private function imagesHint(): string
    {
        return ($this->opts['botSeesImages'] ?? true) === false
            ? $this->tag('p', ['class' => 'cp-thumbs__hint'], $this->e($this->host->t("The bot won't see images")))
            : '';
    }

    private function placeholder(): string
    {
        $given = $this->str('placeholder');
        if ($given !== '') {
            return $given;
        }

        return ($this->opts['canAsk'] ?? false) === true
            ? $this->host->t('Message the room, or type @bot to ask the bot')
            : $this->host->t('Message the room');
    }

    private function str(string $key): string
    {
        $v = $this->opts[$key] ?? '';

        return is_string($v) ? $v : '';
    }
}
