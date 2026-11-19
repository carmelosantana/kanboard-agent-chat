<?php

declare(strict_types=1);

namespace ChatParts\Model;

use ChatParts\Model\Part\Part;
use ChatParts\Model\Part\TextPart;
use InvalidArgumentException;
use JsonSerializable;

final readonly class Message implements JsonSerializable
{
    /** @var list<Part> */
    public array $parts;

    /**
     * @param list<Part>           $parts
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $room,
        public Participant $participant,
        public MessageStatus $status,
        array $parts,
        public array $metadata,
        public int $createdAt,
    ) {
        $this->parts = array_values($parts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'room' => $this->room,
            'participant' => $this->participant->toArray(),
            'status' => $this->status->value,
            'parts' => array_map(static fn (Part $p): array => $p->toArray(), $this->parts),
            'metadata' => $this->metadata,
            'createdAt' => $this->createdAt,
        ];
    }

    /**
     * The JSON shape: toArray() with metadata as an object, so an empty map stays `{}`.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $a = $this->toArray();
        $a['parts'] = $this->parts;
        $a['metadata'] = (object) $this->metadata;

        return $a;
    }

    /**
     * @param array<array-key, mixed> $a
     * @throws InvalidArgumentException for a missing or mistyped key or an unknown enum value
     */
    public static function fromArray(array $a): self
    {
        $parts = [];
        foreach (Fields::array($a, 'parts', 'message') as $i => $p) {
            if (!is_array($p)) {
                throw new InvalidArgumentException(sprintf('Malformed message: part #%s must be an array, got %s.', $i, get_debug_type($p)));
            }
            $parts[] = Part::fromArray($p);
        }

        return new self(
            Fields::string($a, 'id', 'message'),
            Fields::string($a, 'room', 'message'),
            Participant::fromArray(Fields::array($a, 'participant', 'message')),
            Fields::enum($a, 'status', 'message', MessageStatus::class),
            $parts,
            Fields::array($a, 'metadata', 'message', optional: true),
            Fields::int($a, 'createdAt', 'message'),
        );
    }

    /** Text parts only, joined by a blank line; reasoning and tool output are excluded. */
    public function plainText(): string
    {
        $texts = [];
        foreach ($this->parts as $part) {
            if ($part instanceof TextPart) {
                $texts[] = $part->text;
            }
        }

        return implode("\n\n", $texts);
    }

    public function withPart(Part $part): self
    {
        return $this->withParts([...$this->parts, $part]);
    }

    /** Swaps the part with the same id; throws when no part has that id. */
    public function replacePart(Part $part): self
    {
        $parts = $this->parts;
        foreach ($parts as $i => $existing) {
            if ($existing->id === $part->id) {
                $parts[$i] = $part;

                return $this->withParts($parts);
            }
        }

        throw new InvalidArgumentException("No part with id '{$part->id}'.");
    }

    /** @param list<Part> $parts */
    private function withParts(array $parts): self
    {
        return new self(
            $this->id,
            $this->room,
            $this->participant,
            $this->status,
            $parts,
            $this->metadata,
            $this->createdAt,
        );
    }
}
