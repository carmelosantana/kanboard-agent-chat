<?php

namespace Kanboard\Plugin\AgentChat\Model;

use InvalidArgumentException;
use JsonRPC\Exception\AccessDeniedException;
use JsonRPC\Exception\ResponseException;
use Kanboard\Core\Base;
use RuntimeException;

/**
 * Shared plumbing for the JSON-RPC procedures (KnowledgeApi, RoomApi): parameter coercion and
 * the error guard.
 *
 * JSON-RPC errors the client sees: bad params are an InvalidArgumentException (-32602, the plain
 * message in `data`), refusals an AccessDeniedException (403 Forbidden) or a ResponseException
 * the caller built with its own code and `data`, and anything else a
 * RuntimeException with a fixed message and code -32603 (Internal error), never SQL, a path or a
 * trace. The access check runs inside guard() too, so a database error there is just as plain.
 */
abstract class RpcCall extends Base
{
    /** JSON-RPC 2.0 "Internal error". */
    public const INTERNAL_ERROR = -32603;

    /**
     * An int, or a string of digits as a JSON-RPC client may send it, of at least $min.
     * Pass PHP_INT_MIN for "any integer" (the caller clamps it).
     */
    protected static function integer(mixed $value, string $name, int $min): int
    {
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $min) {
            throw new InvalidArgumentException(match ($min) {
                1 => "$name must be a positive integer.",
                0 => "$name must be a non-negative integer.",
                default => "$name must be an integer.",
            });
        }
        return $value;
    }

    /** Runs $call (access check included); any failure but a bad param or a refusal becomes $failure. */
    protected function guard(callable $call, string $failure): mixed
    {
        try {
            return $call();
        } catch (InvalidArgumentException|AccessDeniedException|ResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('AgentChat API call failed: '.$e->getMessage());
            throw new RuntimeException($failure, self::INTERNAL_ERROR);
        }
    }
}
