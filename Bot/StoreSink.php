<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use ChatParts\Model\Message;
use ChatParts\Model\MessageStatus;
use ChatParts\Model\Part\ReasoningPart;
use ChatParts\Model\Part\TextPart;
use ChatParts\Model\Part\ToolPart;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Pimple\Container;

/**
 * Keeps the bot's draft message current for a run nobody streams: room pollers see it as
 * `streaming` with the text so far. Tool rows are written at once; text and reasoning at most
 * every 500 ms (the first at once). final() writes nothing: BotRunner stores the final message.
 */
final class StoreSink implements RunEventSink
{
    private const MIN_INTERVAL_NS = 500_000_000;

    private string $text = '';
    private string $reasoning = '';
    /** @var array<string, ToolPart> part id => latest state, in call order */
    private array $tools = [];
    private ?float $writtenAt = null;

    public function __construct(private readonly Container $container, private readonly int $messageId, private readonly int $runId)
    {
    }

    public function delta(string $text): void
    {
        $this->text .= $text;
        $this->write(false);
    }

    public function reasoning(string $text): void
    {
        $this->reasoning .= $text;
        $this->write(false);
    }

    public function toolCall(ToolPart $p): void
    {
        $this->tools[$p->id] = $p;
        $this->write(true);
    }

    public function toolResult(ToolPart $p): void
    {
        $this->tools[$p->id] = $p;
        $this->write(true);
    }

    public function final(Message $m): void
    {
    }

    private function write(bool $now): void
    {
        $at = (float) hrtime(true);
        if (! $now && $this->writtenAt !== null && $at - $this->writtenAt < self::MIN_INTERVAL_NS) {
            return;
        }
        $this->writtenAt = $at;

        $parts = [];
        if ($this->reasoning !== '') {
            $parts[] = new ReasoningPart("r{$this->runId}-draft-reasoning", $this->reasoning, null);
        }
        array_push($parts, ...array_values($this->tools));
        if ($this->text !== '') {
            $parts[] = new TextPart("r{$this->runId}-draft-text", $this->text);
        }
        (new MessageModel($this->container))->update($this->messageId, ['parts' => $parts, 'status' => MessageStatus::Streaming->value]);
    }
}
