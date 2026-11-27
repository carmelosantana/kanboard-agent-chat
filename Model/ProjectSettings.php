<?php

namespace Kanboard\Plugin\AgentChat\Model;

use InvalidArgumentException;

/**
 * Per-project AgentChat settings, stored in Kanboard's project_metadata.
 * Booleans are stored as "1"/"0"; unknown stored values read as the default. The bot's AI
 * profile is not here: AiConnector's model routing picks it (AiGate::botProfile), and a stored
 * agentchat_ai_profile from 0.1.0 is ignored.
 */
final class ProjectSettings
{
    public const BOT_ENABLED = 'agentchat_bot_enabled';
    public const ASK_ROLE = 'agentchat_ask_role';
    public const BOT_IMAGES = 'agentchat_bot_images';

    /** Who may ask the bot: managers only, members and managers (default), or everyone who can view. */
    public const ASK_ROLES = ['managers', 'members', 'everyone'];
    public const DEFAULT_ASK_ROLE = 'members';

    public function __construct(private $container, private int $projectId)
    {
    }

    public function botEnabled(): bool
    {
        return $this->bool(self::BOT_ENABLED);
    }

    public function askRole(): string
    {
        $role = $this->read(self::ASK_ROLE);
        return in_array($role, self::ASK_ROLES, true) ? $role : self::DEFAULT_ASK_ROLE;
    }

    /** Whether images are sent to the bot (they always stay in the room for people). */
    public function botImages(): bool
    {
        return $this->bool(self::BOT_IMAGES);
    }

    public function setBotEnabled(bool $on): void
    {
        $this->write(self::BOT_ENABLED, $on ? '1' : '0');
    }

    public function setAskRole(string $role): void
    {
        if (! in_array($role, self::ASK_ROLES, true)) {
            throw new InvalidArgumentException('Ask role must be one of: '.implode(', ', self::ASK_ROLES).'.');
        }
        $this->write(self::ASK_ROLE, $role);
    }

    public function setBotImages(bool $on): void
    {
        $this->write(self::BOT_IMAGES, $on ? '1' : '0');
    }

    /** Defaults on: only an explicit "0" turns it off. */
    private function bool(string $key): bool
    {
        return $this->read($key) !== '0';
    }

    /** Not MetadataModel::get(): its `?: $default` turns a stored "0" into the default. */
    private function read(string $key): string
    {
        return (string) ($this->container['projectMetadataModel']->getAll($this->projectId)[$key] ?? '');
    }

    private function write(string $key, string $value): void
    {
        $this->container['projectMetadataModel']->save($this->projectId, [$key => $value]);
    }
}
