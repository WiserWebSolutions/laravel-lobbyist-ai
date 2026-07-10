<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

class VoteLookupTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Look up a single roll-call vote by its identifier for a US state. Returns the description, tallies, and result.';
    }

    public function handle(Request $request): Stringable|string
    {
        $driver = Lobbyist::state((string) $request['state']);

        if (! $driver->supports(Capability::GetVote)) {
            return ToolResult::unsupported($request['state'], 'look up a vote by identifier');
        }

        $vote = $driver->vote((string) $request['identifier']);

        return ToolResult::json([
            'id' => $vote->id,
            'bill_id' => $vote->billId,
            'description' => $vote->description,
            'chamber' => $vote->chamber?->label(),
            'date' => $vote->date?->toDateString(),
            'yea' => $vote->yea,
            'nay' => $vote->nay,
            'not_voting' => $vote->notVoting,
            'absent' => $vote->absent,
            'passed' => $vote->passed,
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
