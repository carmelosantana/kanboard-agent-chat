<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Participant;

/**
 * The room's right rail: People, Agents and Bots, each with a count. An entry with an `online`
 * flag gets a presence dot (with a text equivalent); one without has no dot, so a host with no
 * presence data shows none. Empty groups and unknown group keys are left out.
 */
final class PresenceList extends Component
{
    /** Group key => [participant kind, heading]. */
    private const GROUPS = ['people' => ['human', 'People'], 'agents' => ['agent', 'Agents'], 'bots' => ['bot', 'Bots']];

    /** @param array<string, list<array{name: string, id?: ?string, online?: bool}>> $groups keyed people, agents, bots */
    public function __construct(HostAdapter $host, private readonly array $groups)
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        $sections = '';
        foreach (self::GROUPS as $key => [$kind, $heading]) {
            $entries = $this->groups[$key] ?? [];
            if (!is_array($entries) || $entries === []) {
                continue;
            }
            $items = '';
            foreach ($entries as $entry) {
                $items .= $this->entry(new Participant($kind, isset($entry['id']) ? (string) $entry['id'] : null, (string) ($entry['name'] ?? '')), $entry['online'] ?? null);
            }
            $sections .= $this->tag(
                'section',
                ['class' => 'cp-rail__group'],
                $this->tag('h3', ['class' => 'cp-rail__heading'], $this->e($this->host->t($heading)) . ' ' . $this->tag('span', ['class' => 'cp-rail__count'], (string) count($entries)))
                . $this->tag('ul', ['class' => 'cp-rail__list'], $items),
            );
        }

        return $this->tag('aside', ['class' => 'cp-rail', 'aria-label' => $this->host->t('Members')], $sections);
    }

    private function entry(Participant $p, mixed $online): string
    {
        $dot = is_bool($online) ? $this->tag(
            'span',
            ['class' => 'cp-dot cp-dot--' . ($online ? 'on' : 'off')],
            $this->tag('span', ['class' => 'cp-sr'], $this->e($this->host->t($online ? 'online' : 'offline'))),
        ) : '';

        return $this->tag(
            'li',
            ['class' => 'cp-rail__item'],
            new Avatar($this->host, $p) . $this->tag('span', ['class' => 'cp-rail__name'], $this->e($p->name)) . new Badge($this->host, $p) . $dot,
        );
    }
}
