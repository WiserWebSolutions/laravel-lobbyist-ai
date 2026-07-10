<?php

namespace WiserWebSolutions\Lobbyist\Ai;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\StreamableAgentResponse;
use WiserWebSolutions\Lobbyist\Ai\Agents\BillClassifierAgent;
use WiserWebSolutions\Lobbyist\Ai\Agents\BillSummaryAgent;
use WiserWebSolutions\Lobbyist\Ai\Agents\LegislativeAssistant;
use WiserWebSolutions\Lobbyist\Ai\Agents\VoteSummaryAgent;
use WiserWebSolutions\Lobbyist\Ai\Contracts\EmbeddingStore;
use WiserWebSolutions\Lobbyist\Ai\Support\BillDocument;
use WiserWebSolutions\Lobbyist\Ai\Tools\BillSemanticSearchTool;
use WiserWebSolutions\Lobbyist\Contracts\Capability;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Exceptions\LobbyistException;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

class LobbyistAiManager
{
    public function __construct(protected array $config = []) {}

    // -----------------------------------------------------------------
    // Summaries & classification (structured output, cached)
    // -----------------------------------------------------------------

    /**
     * Summarize a bill into a headline, plain-language summary, and key points.
     *
     * @return array{headline: string, summary: string, key_points: array<int, string>}
     */
    public function summarizeBill(Bill $bill): array
    {
        $key = 'summary:bill:'.$bill->state->abbr().':'.$bill->id.':'.($bill->lastActionDate?->getTimestamp() ?? 0);

        return $this->remember($key, fn () => $this->runStructured(
            new BillSummaryAgent, BillDocument::forBill($bill)
        ));
    }

    /**
     * Summarize a vote / roll call.
     *
     * @return array{headline: string, summary: string}
     */
    public function summarizeVote(Vote $vote): array
    {
        $key = 'summary:vote:'.$vote->id;

        return $this->remember($key, fn () => $this->runStructured(
            new VoteSummaryAgent, BillDocument::forVote($vote)
        ));
    }

    /**
     * Classify a bill by subject and free-form tags.
     *
     * @return array{subjects: array<int, string>, tags: array<int, string>, impact: string}
     */
    public function classifyBill(Bill $bill): array
    {
        $key = 'classify:bill:'.$bill->state->abbr().':'.$bill->id.':'.($bill->lastActionDate?->getTimestamp() ?? 0);

        return $this->remember($key, fn () => $this->runStructured(
            new BillClassifierAgent, BillDocument::forBill($bill)
        ));
    }

    // -----------------------------------------------------------------
    // Q&A agent
    // -----------------------------------------------------------------

    /**
     * Answer a natural-language question, using the driver tools (and semantic
     * search) to gather the facts it needs.
     */
    public function ask(string $question, ?string $state = null): string
    {
        return (string) $this->assistant($state)->prompt(
            $question,
            provider: $this->textProvider(),
            model: $this->textModel(),
        );
    }

    /**
     * Stream the assistant's answer (SSE-friendly).
     */
    public function stream(string $question, ?string $state = null): StreamableAgentResponse
    {
        return $this->assistant($state)->stream(
            $question,
            provider: $this->textProvider(),
            model: $this->textModel(),
        );
    }

    protected function assistant(?string $state): LegislativeAssistant
    {
        return new LegislativeAssistant($state, [new BillSemanticSearchTool($this)]);
    }

    // -----------------------------------------------------------------
    // Semantic search / RAG
    // -----------------------------------------------------------------

    /**
     * Semantic search over indexed bills.
     *
     * @return array<int, array{id: string, score: float, meta: array}>
     */
    public function search(string $query, ?string $state = null, ?int $limit = null): array
    {
        $vector = $this->embed([$query])[0];

        return $this->store()->search(
            $vector,
            $limit ?? (int) ($this->config['rag']['limit'] ?? 10),
            (float) ($this->config['rag']['min_similarity'] ?? 0.0),
            $state,
        );
    }

    /**
     * Embed and index every (new or changed) bill for a state into the store.
     *
     * @return array{state: string, indexed: int, skipped: int, total: int}
     */
    public function index(string $state): array
    {
        $driver = Lobbyist::state($state);

        if (! $driver->supports(Capability::ListBills)) {
            throw new LobbyistException("The [{$state}] driver cannot list bills, so it cannot be indexed.");
        }

        $bills = $driver->bills();
        $store = $this->store();
        $pending = [];
        $skipped = 0;

        foreach ($bills as $bill) {
            $document = BillDocument::forBill($bill);
            $hash = md5($document);
            $id = strtoupper($state).':'.$bill->id;

            if ($store->has($id, $hash)) {
                $skipped++;

                continue;
            }

            $pending[] = ['id' => $id, 'document' => $document, 'hash' => $hash, 'bill' => $bill];
        }

        $indexed = 0;

        foreach (array_chunk($pending, 50) as $chunk) {
            $vectors = $this->embed(array_map(fn ($row) => $row['document'], $chunk));

            foreach (array_values($chunk) as $i => $row) {
                /** @var Bill $bill */
                $bill = $row['bill'];

                $store->upsert($row['id'], $vectors[$i], [
                    'state' => strtoupper($state),
                    'bill_number' => $bill->number,
                    'title' => $bill->title,
                    'url' => $bill->url,
                ], $row['hash']);

                $indexed++;
            }
        }

        return ['state' => strtoupper($state), 'indexed' => $indexed, 'skipped' => $skipped, 'total' => $bills->count()];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Run a structured-output agent and return its validated array.
     */
    protected function runStructured(object $agent, string $prompt): array
    {
        return $agent->prompt(
            $prompt,
            provider: $this->textProvider(),
            model: $this->textModel(),
        )->toArray();
    }

    /**
     * Generate embedding vectors for the given texts using the configured
     * (non-Anthropic) embeddings provider.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        $pending = Embeddings::for($texts);

        if ($dimensions = ($this->config['embeddings']['dimensions'] ?? null)) {
            $pending = $pending->dimensions((int) $dimensions);
        }

        return $pending->generate(
            $this->config['embeddings']['provider'] ?? 'openai',
            $this->config['embeddings']['model'] ?: null,
        )->embeddings;
    }

    protected function store(): EmbeddingStore
    {
        return app(EmbeddingStore::class);
    }

    protected function textProvider(): string
    {
        return $this->config['text']['provider'] ?? 'anthropic';
    }

    protected function textModel(): ?string
    {
        return $this->config['text']['model'] ?? null;
    }

    /**
     * Run a producer through the configured cache, or directly if disabled.
     */
    protected function remember(string $key, callable $producer): mixed
    {
        $cache = $this->config['cache'] ?? [];

        if (! ($cache['enabled'] ?? false)) {
            return $producer();
        }

        return Cache::store($cache['store'] ?? null)->remember(
            'lobbyist-ai:'.md5($this->textModel().'|'.$key),
            (int) ($cache['ttl'] ?? 86400),
            $producer,
        );
    }
}
