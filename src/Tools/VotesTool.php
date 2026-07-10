<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

class VotesTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List recent roll-call votes for a US state legislature. Returns each vote\'s description, date, chamber, and tallies.';
    }

    public function handle(Request $request): Stringable|string
    {
        $driver = Lobbyist::state((string) $request['state']);

        if (! $driver->supports(Capability::ListVotes)) {
            return ToolResult::unsupported($request['state'], 'list votes');
        }

        return ToolResult::json(
            $driver->votes()->take(50)->map(fn (Vote $vote) => [
                'id' => $vote->id,
                'description' => $vote->description,
                'chamber' => $vote->chamber?->label(),
                'date' => $vote->date?->toDateString(),
                'yea' => $vote->yea,
                'nay' => $vote->nay,
                'passed' => $vote->passed,
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
