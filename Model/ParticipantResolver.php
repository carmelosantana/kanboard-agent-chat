<?php

namespace Kanboard\Plugin\AgentChat\Model;

use ChatParts\Model\Participant;
use Kanboard\Core\Base;
use Throwable;

/**
 * Who a Kanboard user is in a room: an `agent` when the Agents plugin's roster lists them,
 * otherwise a `human`. Without Agents, or when its roster can't be read, everyone is a person.
 * The bot is no Kanboard user: bot() is the plugin-defined participant.
 */
class ParticipantResolver extends Base
{
    private const AGENT_TABLE = '\Kanboard\Plugin\Agents\Model\AgentTable';

    /** @param string $rosterClass the class whose presence means Agents is installed (tests name a missing one) */
    public function __construct($container, private string $rosterClass = self::AGENT_TABLE)
    {
        parent::__construct($container);
    }

    public function forUser(int $userId): Participant
    {
        $user = $this->userModel->getById($userId);
        $name = empty($user) ? t('Unknown user') : (string) ($user['name'] ?: $user['username']);

        return new Participant($this->isAgent($userId) ? 'agent' : 'human', (string) $userId, $name);
    }

    public function bot(): Participant
    {
        return new Participant('bot', null, 'project-bot');
    }

    private function isAgent(int $userId): bool
    {
        if (! class_exists($this->rosterClass)) {
            return false;
        }
        try {
            return (new ($this->rosterClass)($this->container))->isAgent($userId);
        } catch (Throwable) {
            return false;
        }
    }
}
