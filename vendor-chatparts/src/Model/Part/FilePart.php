<?php

declare(strict_types=1);

namespace ChatParts\Model\Part;

use ChatParts\Model\Fields;

final readonly class FilePart extends Part
{
    public function __construct(
        string $id,
        public string $name,
        public string $mime,
        public string $url,
        public ?string $thumbUrl,
    ) {
        parent::__construct($id);
    }

    public function type(): string
    {
        return 'file';
    }

    public function toArray(): array
    {
        return [
            'type' => 'file',
            'id' => $this->id,
            'name' => $this->name,
            'mime' => $this->mime,
            'url' => $this->url,
            'thumbUrl' => $this->thumbUrl,
        ];
    }

    protected static function fromFields(array $a): static
    {
        return new self(
            Fields::string($a, 'id', 'file part'),
            Fields::string($a, 'name', 'file part'),
            Fields::string($a, 'mime', 'file part'),
            Fields::string($a, 'url', 'file part'),
            Fields::nullableString($a, 'thumbUrl', 'file part'),
        );
    }
}
