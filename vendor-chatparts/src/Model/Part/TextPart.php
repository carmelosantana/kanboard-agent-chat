<?php

declare(strict_types=1);

namespace ChatParts\Model\Part;

use ChatParts\Model\Fields;

final readonly class TextPart extends Part
{
    public function __construct(string $id, public string $text)
    {
        parent::__construct($id);
    }

    public function type(): string
    {
        return 'text';
    }

    public function toArray(): array
    {
        return ['type' => 'text', 'id' => $this->id, 'text' => $this->text];
    }

    protected static function fromFields(array $a): static
    {
        return new self(Fields::string($a, 'id', 'text part'), Fields::string($a, 'text', 'text part'));
    }
}
