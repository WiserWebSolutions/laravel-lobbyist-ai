<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

class RepresentativesTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List the elected representatives (legislators) currently serving in a US state legislature. Returns name, party, chamber, and district.';
    }

    public function handle(Request $request): Stringable|string
    {
        $driver = Lobbyist::state((string) $request['state']);

        if (! $driver->supports(Capability::ListLegislators)) {
            return ToolResult::unsupported($request['state'], 'list representatives');
        }

        return ToolResult::json(
            $driver->legislators()->take(300)->map(fn (Legislator $legislator) => [
                'name' => $legislator->name,
                'party' => $legislator->party->label(),
                'chamber' => $legislator->chamber?->label(),
                'district' => $legislator->district,
                'role' => $legislator->role,
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
