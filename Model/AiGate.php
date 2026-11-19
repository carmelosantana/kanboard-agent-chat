<?php

namespace Kanboard\Plugin\AgentChat\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Kanboard\Plugin\AiConnector\Plugin as AiConnectorPlugin;

/**
 * AiGate: the single answer to "can the project bot run?".
 *
 * Gate = PHP >= 8.4 AND AiConnector present AND AiConnector >= MIN_CONNECTOR_VERSION
 * AND php-agents has RunLimits (0.17+) AND ProviderRegistry::isReady(). Any exception closes it.
 *
 * Ask it at request time, not in Plugin::initialize(): RunLimits comes from AiConnector's
 * vendor autoload, which AiConnector registers in its own initialize(), and Kanboard gives no
 * plugin load order. The nullable arguments override each branch for tests.
 */
final class AiGate
{
    public const MIN_CONNECTOR_VERSION = '1.2.0';

    public static function isReady(
        $container,
        ?int $phpVersionId = null,
        ?bool $connectorPresent = null,
        ?string $connectorVersion = null,
        ?bool $runLimitsPresent = null
    ): bool {
        try {
            if (($phpVersionId ?? PHP_VERSION_ID) < 80400) {
                return false;
            }
            if (! ($connectorPresent ?? class_exists(ProviderRegistry::class))) {
                return false;
            }
            $version = $connectorVersion ?? self::installedConnectorVersion($container);
            if ($version === null || version_compare($version, self::MIN_CONNECTOR_VERSION, '<')) {
                return false;
            }
            if (! ($runLimitsPresent ?? class_exists(\CarmeloSantana\PHPAgents\Agent\RunLimits::class))) {
                return false;
            }
            return (new ProviderRegistry($container))->isReady();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The gate is open and the project has its bot switched on. */
    public static function isOpenFor($container, int $projectId): bool
    {
        return (new ProjectSettings($container, $projectId))->botEnabled() && self::isReady($container);
    }

    private static function installedConnectorVersion($container): ?string
    {
        return class_exists(AiConnectorPlugin::class)
            ? (new AiConnectorPlugin($container))->getPluginVersion()
            : null;
    }
}
