<?php

declare(strict_types=1);

namespace ChatParts\Model\Part;

use ChatParts\Model\Fields;
use InvalidArgumentException;
use JsonSerializable;

abstract readonly class Part implements JsonSerializable
{
    public function __construct(public string $id)
    {
    }

    abstract public function type(): string;

    /** @return array<string, mixed> always carries 'type' and 'id' */
    abstract public function toArray(): array;

    /** @return array<string, mixed> toArray() with map-valued fields as objects, so empty maps stay `{}` */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The one way to rebuild a part from a stored row: dispatches on 'type'. Called on a
     * subclass (`TextPart::fromArray()`), it also refuses a row of any other type.
     *
     * @param array<array-key, mixed> $a
     * @throws InvalidArgumentException for an unknown type or a missing or mistyped key
     */
    final public static function fromArray(array $a): static
    {
        $type = Fields::string($a, 'type', 'part');
        $part = match ($type) {
            'text' => TextPart::fromFields($a),
            'reasoning' => ReasoningPart::fromFields($a),
            'tool' => ToolPart::fromFields($a),
            'source' => SourcePart::fromFields($a),
            'file' => FilePart::fromFields($a),
            'data' => DataPart::fromFields($a),
            default => throw new InvalidArgumentException('Unknown part type: ' . var_export($type, true)),
        };
        if (!$part instanceof static) {
            throw new InvalidArgumentException(sprintf("%s::fromArray() was given a '%s' part.", static::class, $type));
        }

        return $part;
    }

    /**
     * Builds the part from a row whose 'type' the dispatcher has already matched.
     *
     * @param array<array-key, mixed> $a
     */
    abstract protected static function fromFields(array $a): static;
}
