<?php

declare(strict_types=1);

namespace ChatParts\Model\Part;

use ChatParts\Model\Fields;
use ChatParts\Model\ToolState;

final readonly class ToolPart extends Part
{
    /** @param array<string, mixed> $input */
    public function __construct(
        string $id,
        public string $name,
        public array $input,
        public ToolState $state,
        public ?string $outputSummary,
        public ?string $error,
    ) {
        parent::__construct($id);
    }

    public function type(): string
    {
        return 'tool';
    }

    public function toArray(): array
    {
        return [
            'type' => 'tool',
            'id' => $this->id,
            'name' => $this->name,
            'input' => $this->input,
            'state' => $this->state->value,
            'outputSummary' => $this->outputSummary,
            'error' => $this->error,
        ];
    }

    public function jsonSerialize(): array
    {
        $a = $this->toArray();
        $a['input'] = (object) $this->input;

        return $a;
    }

    protected static function fromFields(array $a): static
    {
        return new self(
            Fields::string($a, 'id', 'tool part'),
            Fields::string($a, 'name', 'tool part'),
            Fields::array($a, 'input', 'tool part', optional: true),
            Fields::enum($a, 'state', 'tool part', ToolState::class),
            Fields::nullableString($a, 'outputSummary', 'tool part'),
            Fields::nullableString($a, 'error', 'tool part'),
        );
    }
}
