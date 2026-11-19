<?php

// php-agents ships inside AiConnector, a sibling checkout (as in the plugins/ folder).
require_once dirname(__DIR__, 3).'/AiConnector/vendor/autoload.php';

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;

/**
 * A scripted php-agents provider: each stream() call plays the next turn of the script, so
 * tests drive the real AbstractAgent loop. A turn is a list of Response chunks and closures
 * (run between chunks, for side effects such as a stop request), or a Throwable to throw.
 * Every call's messages and tool names are recorded.
 */
final class FakeProvider implements ProviderInterface
{
    /** @var list<array{messages: list<MessageInterface>, tools: list<string>}> */
    public array $calls = [];

    /** @param list<list<Response|Closure>|Throwable> $turns */
    public function __construct(private array $turns, private string $model = 'fake-model')
    {
    }

    public static function text(string $text): Response
    {
        return new Response($text, ProviderFinishReason::Stop, model: 'fake-model');
    }

    public static function reasoning(string $text): Response
    {
        return new Response('', ProviderFinishReason::Stop, reasoning: $text);
    }

    /** One chunk carrying tool calls, as providers send them at the end of a turn. */
    public static function tools(ToolCall ...$calls): Response
    {
        return new Response('', ProviderFinishReason::ToolUse, $calls, 'fake-model');
    }

    public static function call(string $id, string $name, array $arguments): ToolCall
    {
        return new ToolCall($id, $name, $arguments);
    }

    public static function usage(int $prompt, int $completion): Response
    {
        return new Response('', ProviderFinishReason::Stop, usage: new Usage($prompt, $completion, $prompt + $completion));
    }

    public static function finish(ProviderFinishReason $reason): Response
    {
        return new Response('', $reason);
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        $this->calls[] = ['messages' => $messages, 'tools' => array_map(static fn ($t): string => $t->name(), $tools)];
        $turn = array_shift($this->turns) ?? throw new LogicException('FakeProvider script exhausted.');
        if ($turn instanceof Throwable) {
            throw $turn;
        }
        foreach ($turn as $chunk) {
            if ($chunk instanceof Closure) {
                $chunk();
                continue;
            }
            yield $chunk;
        }
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        throw new LogicException('The bot streams; chat() is not used.');
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        throw new LogicException('structured() is not used.');
    }

    public function models(): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;
        return $clone;
    }

    /** Every text the provider was sent across all calls, joined. */
    public function sentText(): string
    {
        $texts = [];
        foreach ($this->calls as $call) {
            foreach ($call['messages'] as $m) {
                $content = $m->content();
                $texts[] = is_string($content) ? $content : json_encode($content);
            }
        }
        return implode("\n", $texts);
    }
}
