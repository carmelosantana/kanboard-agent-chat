<?php

declare(strict_types=1);

namespace ChatParts\Model;

enum FinishReason: string
{
    case Stop = 'stop';
    case Stopped = 'stopped';
    case BudgetExhausted = 'budget_exhausted';
    case Refusal = 'refusal';
    case ContentFilter = 'content_filter';
    case ContextWindowExceeded = 'context_window_exceeded';
    case MaxIterations = 'max_iterations';
    case Error = 'error';

    /** Short receipt shown under a finished message; empty for a normal finish. */
    public function receiptText(): string
    {
        return match ($this) {
            self::Stop => '',
            self::Stopped => 'stopped',
            self::BudgetExhausted => 'stopped: run budget reached',
            self::Refusal => 'the provider refused',
            self::ContentFilter => 'stopped: content filter',
            self::ContextWindowExceeded => 'stopped: too much context',
            self::MaxIterations => 'stopped: step limit reached',
            self::Error => "couldn't answer",
        };
    }
}
