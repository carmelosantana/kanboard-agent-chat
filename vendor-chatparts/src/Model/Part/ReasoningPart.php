<?php

declare(strict_types=1);

namespace ChatParts\Model\Part;

use ChatParts\Model\Fields;

final readonly class ReasoningPart extends Part
{
    public function __construct(string $id, public string $text, public ?int $durationMs)
    {
        parent::__construct($id);
    }

    public function type(): string
    {
        return 'reasoning';
    }

    public function toArray(): array
    {
        return ['type' => 'reasoning', 'id' => $this->id, 'text' => $this->text, 'durationMs' => $this->durationMs];
    }

    protected static function fromFields(array $a): static
    {
        return new self(
            Fields::string($a, 'id', 'reasoning part'),
            Fields::string($a, 'text', 'reasoning part'),
            Fields::nullableInt($a, 'durationMs', 'reasoning part'),
        );
    }
}
