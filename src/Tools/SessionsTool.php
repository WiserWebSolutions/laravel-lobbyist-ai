<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Data\Session;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

class SessionsTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List the legislative sessions available for a US state. Returns each session\'s id, name, and title.';
    }

    public function handle(Request $request): Stringable|string
    {
        $driver = Lobbyist::state((string) $request['state']);

        if (! $driver->supports(Capability::ListSessions)) {
            return ToolResult::unsupported($request['state'], 'list sessions');
        }

        return ToolResult::json(
            $driver->sessions()->map(fn (Session $session) => [
                'id' => $session->id,
                'name' => $session->name,
                'title' => $session->title,
            ])->values()->all()
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()->required(),
        ];
    }
}
