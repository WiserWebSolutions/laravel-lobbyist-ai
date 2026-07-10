<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tools;

/**
 * Small helpers for shaping tool return payloads consistently.
 */
class ToolResult
{
    public static function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public static function unsupported(string $state, string $operation): string
    {
        return self::json([
            'error' => "The [{$state}] driver does not support the operation: {$operation}.",
        ]);
    }
}
