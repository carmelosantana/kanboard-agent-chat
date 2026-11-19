<?php

declare(strict_types=1);

namespace ChatParts\Model\Part;

use ChatParts\Model\Fields;

/** Host-defined structured payload; $type names the payload kind (serialised as 'dataType'). */
final readonly class DataPart extends Part
{
    /** @param array<string, mixed> $payload */
    public function __construct(string $id, public string $type, public array $payload)
    {
        parent::__construct($id);
    }

    public function type(): string
    {
        return 'data';
    }

    public function toArray(): array
    {
        return ['type' => 'data', 'id' => $this->id, 'dataType' => $this->type, 'payload' => $this->payload];
    }

    public function jsonSerialize(): array
    {
        $a = $this->toArray();
        $a['payload'] = (object) $this->payload;

        return $a;
    }

    protected static function fromFields(array $a): static
    {
        return new self(
            Fields::string($a, 'id', 'data part'),
            Fields::string($a, 'dataType', 'data part'),
            Fields::array($a, 'payload', 'data part', optional: true),
        );
    }
}
