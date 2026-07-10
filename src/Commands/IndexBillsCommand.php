<?php

namespace WiserWebSolutions\Lobbyist\Ai\Commands;

use Illuminate\Console\Command;
use WiserWebSolutions\Lobbyist\Ai\LobbyistAiManager;
use WiserWebSolutions\Lobbyist\Exceptions\LobbyistException;

class IndexBillsCommand extends Command
{
    protected $signature = 'lobbyist-ai:index {state : Two-letter state code to index (e.g. PA, CA, US)}';

    protected $description = 'Embed and index a state\'s bills for semantic search (incremental — unchanged bills are skipped).';

    public function handle(LobbyistAiManager $manager): int
    {
        $state = strtoupper((string) $this->argument('state'));

        $this->info("Indexing bills for [{$state}] — this generates embeddings and may take a while on first run.");

        try {
            $result = $manager->index($state);
        } catch (LobbyistException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Done: %d indexed, %d unchanged (skipped), %d total.',
            $result['indexed'],
            $result['skipped'],
            $result['total'],
        ));

        return self::SUCCESS;
    }
}
