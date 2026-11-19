<?php

namespace Kanboard\Plugin\AgentChat\Model;

use ChatParts\Model\Participant;
use Kanboard\Core\Base;
use Throwable;

/**
 * Who a Kanboard user is in a room: an `agent` when the Agents plugin's roster lists them,
 * otherwise a `human`. Without Agents, or when its roster can't be read, everyone is a person.
 * The bot is no Kanboard user: bot() is the plugin-defined participant bot:project, as
 * MessageModel renders stored bot rows.
 */
class ParticipantResolver extends Base
{
    private const AGENT_TABLE = '\Kanboard\Plugin\Agents\Model\AgentTable';

    /** A class name in the container under this key replaces AGENT_TABLE (tests name a missing one). */
    public const ROSTER_OVERRIDE = 'agentchat.agents_roster_class';

    private string $rosterClass;

    /** @param ?string $rosterClass the class whose presence means Agents is installed */
    public function __construct($container, ?string $rosterClass = null)
    {
        parent::__construct($container);
        $this->rosterClass = $rosterClass
            ?? ($container->offsetExists(self::ROSTER_OVERRIDE) ? (string) $container[self::ROSTER_OVERRIDE] : self::AGENT_TABLE);
    }

    public function forUser(int $userId): Participant
    {
        $user = $this->userModel->getById($userId);
        $name = empty($user) ? t('Unknown user') : (string) ($user['name'] ?: $user['username']);

        return new Participant($this->isAgent($userId) ? 'agent' : 'human', (string) $userId, $name);
    }

    public function bot(): Participant
    {
        return new Participant('bot', 'project', t('Project bot'));
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
