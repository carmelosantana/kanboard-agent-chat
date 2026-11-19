<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Participant;

/**
 * The `@` list: who can be mentioned (the bot, agents, people), as a closed listbox that
 * chatparts.js opens, filters and navigates. Picking an entry inserts `@{handle}`. The host
 * builds the list, so leaving the bot out (asking is off for this user) leaves it out here.
 */
final class MentionPicker extends Component
{
    /** @param list<array{kind: string, handle: string, name: string, id?: ?string}> $entries in display order */
    public function __construct(HostAdapter $host, private readonly array $entries)
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        $items = '';
        foreach (array_values($this->entries) as $i => $entry) {
            $p = new Participant((string) ($entry['kind'] ?? ''), isset($entry['id']) ? (string) $entry['id'] : null, (string) ($entry['name'] ?? ''));
            $items .= $this->tag(
                'li',
                ['id' => 'cp-mention-' . $i, 'class' => 'cp-mention__item', 'role' => 'option', 'aria-selected' => 'false', 'data-handle' => (string) ($entry['handle'] ?? '')],
                new Avatar($this->host, $p)
                . $this->tag('span', ['class' => 'cp-mention__name'], $this->e($p->name))
                . new Badge($this->host, $p),
            );
        }

        return $this->tag('ul', ['id' => 'cp-mention', 'class' => 'cp-mention', 'role' => 'listbox', 'aria-label' => $this->host->t('Mention someone'), 'hidden' => ''], $items);
    }
}
