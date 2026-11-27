<?php

namespace Kanboard\Plugin\AgentChat\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Plugin as AiConnectorPlugin;

/**
 * AiGate: the single answer to "can the project bot run?".
 *
 * Gate = PHP >= 8.4 AND AiConnector present AND AiConnector >= MIN_CONNECTOR_VERSION with its
 * routing API (ProviderRegistry::resolve) AND php-agents has RunLimits (0.17+) AND
 * ProviderRegistry::isReady(). Any exception closes it. For a project, the gate also needs
 * AiConnector's routing to name a ready profile for the bot (FEATURE): botProfile().
 *
 * Ask it at request time, not in Plugin::initialize(): RunLimits comes from AiConnector's
 * vendor autoload, which AiConnector registers in its own initialize(), and Kanboard gives no
 * plugin load order. The nullable arguments override each branch for tests; a bool in the
 * container under CONNECTOR_OVERRIDE stands in for "is AiConnector there" (tests of the
 * degraded room, where its classes are loaded anyway).
 *
 * isInstalled() is the gate without the provider check: AiConnector, new enough, with a
 * php-agents that has RunLimits. Without it there is no bot at all on this instance.
 */
final class AiGate
{
    public const MIN_CONNECTOR_VERSION = '1.4.0';
    /** The bot's AiConnector feature id, registered on aiconnector:features. */
    public const FEATURE = 'agentchat.bot';
    public const CONNECTOR_OVERRIDE = 'agentchat.ai_connector_present';

    public static function isReady(
        $container,
        ?int $phpVersionId = null,
        ?bool $connectorPresent = null,
        ?string $connectorVersion = null,
        ?bool $runLimitsPresent = null,
        ?bool $routingPresent = null
    ): bool {
        try {
            return self::isInstalled($container, $phpVersionId, $connectorPresent, $connectorVersion, $runLimitsPresent, $routingPresent)
                && (new ProviderRegistry($container))->isReady();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** AiConnector is there, new enough and routing, on PHP 8.4+, with php-agents' RunLimits; providers aside. */
    public static function isInstalled(
        $container,
        ?int $phpVersionId = null,
        ?bool $connectorPresent = null,
        ?string $connectorVersion = null,
        ?bool $runLimitsPresent = null,
        ?bool $routingPresent = null
    ): bool {
        try {
            if (($phpVersionId ?? PHP_VERSION_ID) < 80400) {
                return false;
            }
            if (! ($connectorPresent ?? self::connectorPresent($container))) {
                return false;
            }
            $version = $connectorVersion ?? self::installedConnectorVersion($container);
            if ($version === null || version_compare($version, self::MIN_CONNECTOR_VERSION, '<')) {
                return false;
            }
            if (! ($routingPresent ?? self::routingPresent())) {
                return false;
            }
            return $runLimitsPresent ?? class_exists(\CarmeloSantana\PHPAgents\Agent\RunLimits::class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Whether AiConnector's classes are there (its profiles can be listed). */
    public static function connectorPresent($container): bool
    {
        return $container->offsetExists(self::CONNECTOR_OVERRIDE)
            ? (bool) $container[self::CONNECTOR_OVERRIDE]
            : class_exists(ProviderRegistry::class);
    }

    /**
     * The profile id AiConnector routes the project's bot to, or '' when none is ready (or
     * AiConnector, or its routing API, is missing). The only source of the bot's profile.
     */
    public static function botProfile($container, int $projectId): string
    {
        try {
            if (! self::connectorPresent($container) || ! self::routingPresent()) {
                return '';
            }
            return (new ProviderRegistry($container))->resolve(self::FEATURE, $projectId);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The gate is open and AiConnector routes the project's bot to a ready profile. */
    public static function isReadyFor($container, int $projectId): bool
    {
        return self::isReady($container) && self::botProfile($container, $projectId) !== '';
    }

    /** The gate is open for the project and the project has its bot switched on. */
    public static function isOpenFor($container, int $projectId): bool
    {
        return (new ProjectSettings($container, $projectId))->botEnabled() && self::isReadyFor($container, $projectId);
    }

    /** AiConnector 1.4.0's routing API, feature-detected (method_exists autoloads, never fatals). */
    private static function routingPresent(): bool
    {
        return method_exists(ProviderRegistry::class, 'resolve');
    }

    private static function installedConnectorVersion($container): ?string
    {
        return class_exists(AiConnectorPlugin::class)
            ? (new AiConnectorPlugin($container))->getPluginVersion()
            : null;
    }
}
