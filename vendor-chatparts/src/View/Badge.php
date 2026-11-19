<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Participant;

/** The kind label beside a name: "Agent" or "Bot". People and system lines get none. */
final class Badge extends Component
{
    public function __construct(HostAdapter $host, private readonly Participant $p)
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        return match (Avatar::kind($this->p)) {
            'agent' => $this->tag('span', ['class' => 'cp-badge cp-badge--agent'], $this->e($this->host->t('Agent'))),
            'bot' => $this->tag('span', ['class' => 'cp-badge cp-badge--bot'], $this->e($this->host->t('Bot'))),
            default => '',
        };
    }
}
