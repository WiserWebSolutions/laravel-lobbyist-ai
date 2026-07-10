<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

class BillLookupTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Look up a single bill by its number (e.g. "HB17") or id for a US state. Returns full details including description and last action.';
    }

    public function handle(Request $request): Stringable|string
    {
        $driver = Lobbyist::state((string) $request['state']);

        if (! $driver->supports(Capability::GetBill)) {
            return ToolResult::unsupported($request['state'], 'look up a bill by identifier');
        }

        $bill = $driver->bill((string) $request['identifier']);

        return ToolResult::json([
            'number' => $bill->number,
            'title' => $bill->title,
            'description' => $bill->description,
            'status' => $bill->status,
            'chamber' => $bill->chamber?->label(),
            'last_action' => $bill->lastAction,
            'last_action_date' => $bill->lastActionDate?->toDateString(),
            'url' => $bill->url,
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
