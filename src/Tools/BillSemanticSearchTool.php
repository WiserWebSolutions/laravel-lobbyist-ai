<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use WiserWebSolutions\Lobbyist\Ai\LobbyistAiManager;

/**
 * Retrieval-augmented search over the indexed bill corpus. Embeds the query
 * and returns the most semantically similar bills from the embedding store.
 */
class BillSemanticSearchTool implements Tool
{
    public function __construct(protected LobbyistAiManager $manager) {}

    public function description(): Stringable|string
    {
        return 'Search indexed bills by meaning/topic (not keywords). Use this to find bills relevant to a subject, then look up specifics. Optionally scope to a two-letter state code.';
    }

    public function handle(Request $request): Stringable|string
    {
        $matches = $this->manager->search(
            (string) $request['query'],
            $request['state'] ?? null,
        );

        return ToolResult::json(array_map(fn ($match) => [
            'bill_number' => $match['meta']['bill_number'] ?? null,
            'title' => $match['meta']['title'] ?? null,
            'state' => $match['meta']['state'] ?? null,
            'score' => $match['score'],
        ], $matches));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'state' => $schema->string(),
        ];
    }
}
