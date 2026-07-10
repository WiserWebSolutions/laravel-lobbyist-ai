<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

class RepresentativeLookupTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Look up a single elected representative by identifier for a US state. Returns name, party, chamber, district, and role.';
    }

    public function handle(Request $request): Stringable|string
    {
        $driver = Lobbyist::state((string) $request['state']);

        if (! $driver->supports(Capability::GetRepresentative)) {
            return ToolResult::unsupported($request['state'], 'look up a representative by identifier');
        }

        $legislator = $driver->representative((string) $request['identifier']);

        return ToolResult::json([
            'name' => $legislator->name,
            'first_name' => $legislator->firstName,
            'last_name' => $legislator->lastName,
            'party' => $legislator->party->label(),
            'chamber' => $legislator->chamber?->label(),
            'district' => $legislator->district,
            'role' => $legislator->role,
            'url' => $legislator->url,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()->required(),
            'identifier' => $schema->string()->required(),
        ];
    }
}
