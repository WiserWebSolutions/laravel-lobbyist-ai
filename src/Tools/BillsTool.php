<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

class BillsTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List current bills for a US state legislature. Returns each bill\'s number, title, status, chamber, and last action. Use "US" for the federal Congress.';
    }

    public function handle(Request $request): Stringable|string
    {
        $driver = Lobbyist::state((string) $request['state']);

        if (! $driver->supports(Capability::ListBills)) {
            return ToolResult::unsupported($request['state'], 'list bills');
        }

        return ToolResult::json(
            $driver->bills()->take(50)->map(fn (Bill $bill) => [
                'number' => $bill->number,
                'title' => $bill->title,
                'status' => $bill->status,
                'chamber' => $bill->chamber?->label(),
                'last_action' => $bill->lastAction,
                'url' => $bill->url,
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
