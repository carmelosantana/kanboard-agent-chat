<?php

declare(strict_types=1);

namespace ChatParts\Model\Part;

use ChatParts\Model\Fields;

final readonly class SourcePart extends Part
{
    /**
     * @param int    $n    citation number shown in the text, 1-based
     * @param string $kind task|comment|knowledge
     * @param string $ref  host-side identifier of the cited record
     */
    public function __construct(
        string $id,
        public int $n,
        public string $title,
        public string $url,
        public string $kind,
        public string $ref,
    ) {
        parent::__construct($id);
    }

    public function type(): string
    {
        return 'source';
    }

    public function toArray(): array
    {
        return [
            'type' => 'source',
            'id' => $this->id,
            'n' => $this->n,
            'title' => $this->title,
            'url' => $this->url,
            'kind' => $this->kind,
            'ref' => $this->ref,
        ];
    }

    protected static function fromFields(array $a): static
    {
        return new self(
            Fields::string($a, 'id', 'source part'),
            Fields::int($a, 'n', 'source part'),
            Fields::string($a, 'title', 'source part'),
            Fields::string($a, 'url', 'source part'),
            Fields::string($a, 'kind', 'source part'),
            Fields::string($a, 'ref', 'source part'),
        );
    }
}
