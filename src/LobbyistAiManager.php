<?php

namespace WiserWebSolutions\Lobbyist\Ai;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\EmbeddingsResponse;
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
use WiserWebSolutions\Lobbyist\Data\BillText;
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
     * `$attachments` lets a caller supply document content the DTO itself
     * cannot carry generically -- most importantly the bill's full text.
     * {@see BillText} rarely has bytes on
     * it (most sources require a separate fetch per version, which is a
     * metered, driver-specific concern this package knows nothing about), so
     * a caller that has already fetched the documents passes them here
     * rather than this method trying to fetch anything itself. Anything
     * `Laravel\Ai\Promptable::prompt()`'s own `attachments` parameter accepts
     * works, e.g. `Laravel\Ai\Files\Document::fromString(...)`.
     *
     * @param  array<int, mixed>  $attachments
     * @return array{headline: string, summary: string, key_points: array<int, string>}
     */
    public function summarizeBill(Bill $bill, array $attachments = []): array
    {
        $key = 'summary:bill:'.$bill->state->abbr().':'.$bill->id.':'.self::revisionOf($bill)
            // Attachments change what the model can say without moving the
            // bill's own revision marker, so a metadata-only summary cached
            // before they were available must not mask a richer one now.
            .':attachments:'.count($attachments);

        return $this->remember($key, fn () => $this->runStructured(
            new BillSummaryAgent, BillDocument::forBill($bill), $attachments
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
        $key = 'classify:bill:'.$bill->state->abbr().':'.$bill->id.':'.self::revisionOf($bill);

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
     * A thin wrapper over {@see self::indexDocuments()} for a caller that
     * wants this package to fetch the bills itself, via the driver -- the
     * only case that suits a caller with no local mirror of its own. A
     * caller that already holds the bills (as boardadvocate does, having
     * synced them) should call {@see self::indexDocuments()} directly:
     * routing through here would mean an extra, metered driver fetch of data
     * already on hand.
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

        $rows = [];
        foreach ($bills as $bill) {
            $rows[] = [
                'id' => strtoupper($state).':'.$bill->id,
                'document' => BillDocument::forBill($bill),
                'meta' => [
                    'state' => strtoupper($state),
                    'bill_number' => $bill->number,
                    'title' => $bill->title,
                    'url' => $bill->url,
                ],
            ];
        }

        $result = $this->indexDocuments($rows);

        return ['state' => strtoupper($state), ...$result, 'total' => $bills->count()];
    }

    /**
     * Embed and index arbitrary already-fetched documents into the store,
     * skipping any whose content hash is unchanged since it was last indexed.
     *
     * The primitive {@see self::index()} is built on. Exists so a caller that
     * already holds its own corpus -- a synced mirror, not a live driver
     * fetch -- can index it without this package spending a metered call to
     * refetch data it does not need.
     *
     * @param  iterable<int, array{id: string, document: string, meta: array<string, mixed>}>  $rows
     * @return array{indexed: int, skipped: int}
     */
    public function indexDocuments(iterable $rows): array
    {
        $store = $this->store();
        $pending = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $hash = md5($row['document']);

            if ($store->has($row['id'], $hash)) {
                $skipped++;

                continue;
            }

            $pending[] = ['id' => $row['id'], 'document' => $row['document'], 'meta' => $row['meta'], 'hash' => $hash];
        }

        $indexed = 0;

        foreach (array_chunk($pending, 50) as $chunk) {
            $response = $this->embedWithMeta(array_map(fn ($row) => $row['document'], $chunk));

            $store->upsertMany(array_map(fn ($row, $vector) => [
                'id' => $row['id'],
                'vector' => $vector,
                'meta' => $row['meta'],
                'contentHash' => $row['hash'],
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
            ], array_values($chunk), $response->embeddings));

            $indexed += count($chunk);
        }

        return ['indexed' => $indexed, 'skipped' => $skipped];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Run a structured-output agent and return its validated array.
     *
     * @param  array<int, mixed>  $attachments
     */
    protected function runStructured(object $agent, string $prompt, array $attachments = []): array
    {
        return $agent->prompt(
            $prompt,
            attachments: $attachments,
            provider: $this->textProvider(),
        )->toArray();
    }

    /**
     * Generate embedding vectors for the given texts using the configured
     * embeddings provider (falls back to Laravel AI's own default).
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        return $this->embedWithMeta($texts)->embeddings;
    }

    /**
     * {@see self::embed()}, keeping the response's provider/model metadata --
     * needed by {@see self::indexDocuments()} so a stored embedding carries
     * the same provenance as {@see self::summarizeBill()} and
     * {@see self::classifyBill()} do.
     *
     * @param  array<int, string>  $texts
     */
    protected function embedWithMeta(array $texts): EmbeddingsResponse
    {
        $pending = Embeddings::for($texts);

        if ($dimensions = ($this->config['embeddings']['dimensions'] ?? null)) {
            $pending = $pending->dimensions((int) $dimensions);
        }

        return $pending->generate(
            $this->config['embeddings']['provider'] ?? null,
        );
    }

    protected function store(): EmbeddingStore
    {
        return app(EmbeddingStore::class);
    }

    protected function textProvider(): ?string
    {
        return $this->config['text']['provider'] ?? null;
    }

    /**
     * Run a producer through the configured cache, or directly if disabled.
     */
    /**
     * A marker that moves when the bill does, for cache keying.
     *
     * Prefers changeHash, the source's own revision marker. lastActionDate was
     * the original choice and is unusable on its own: LegiScan's getBill payload
     * carries a history array but no last-action date, so the DTO's is null for
     * every bill it maps -- a whole 4,963-bill session verified at zero. Keying
     * on that meant a summary generated the day a bill was introduced was still
     * being served after it was amended, passed and signed.
     */
    protected static function revisionOf(Bill $bill): string
    {
        return $bill->changeHash
            ?? (string) ($bill->lastActionDate?->getTimestamp() ?? 0);
    }

    protected function remember(string $key, callable $producer): mixed
    {
        $cache = $this->config['cache'] ?? [];

        if (! ($cache['enabled'] ?? false)) {
            return $producer();
        }

        return Cache::store($cache['store'] ?? null)->remember(
            'lobbyist-ai:'.md5($key),
            (int) ($cache['ttl'] ?? 86400),
            $producer,
        );
    }
}
