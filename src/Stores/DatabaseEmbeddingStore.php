<?php

namespace WiserWebSolutions\Lobbyist\Ai\Stores;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use WiserWebSolutions\Lobbyist\Ai\Contracts\EmbeddingStore;
use WiserWebSolutions\Lobbyist\Ai\Support\Vector;

/**
 * Default embedding store: vectors live in a regular database table on the
 * application's default connection, packed as binary float32 rather than
 * JSON, with a binary sign-bit signature alongside each one.
 *
 * A search or neighbor lookup runs two passes rather than scanning the corpus
 * exactly: {@see self::candidateIds()} narrows a whole state down to a few
 * hundred candidates by cheap Hamming distance over the signatures, then
 * {@see self::rerank()} scores only those candidates by exact dot product.
 * Exhaustive exact scoring does not scale past a few thousand documents in
 * PHP -- see {@see Vector} for the reasoning -- but the two-pass approach
 * keeps a full state's corpus (tens of thousands of bills) affordable while
 * still requiring no Postgres/pgvector or external vector store.
 */
class DatabaseEmbeddingStore implements EmbeddingStore
{
    /**
     * How many candidates the signature prefilter keeps for the exact
     * rerank. Large enough that a genuinely close match essentially never
     * falls outside it, small enough that loading their full vectors stays
     * cheap.
     */
    private const CANDIDATE_POOL = 200;

    public function __construct(
        protected ?string $connection = null,
        protected string $table = 'lobbyist_ai_bill_embeddings',
    ) {}

    public function upsert(string $id, array $vector, array $meta, string $contentHash, ?string $provider = null, ?string $model = null): void
    {
        $this->upsertMany([[
            'id' => $id,
            'vector' => $vector,
            'meta' => $meta,
            'contentHash' => $contentHash,
            'provider' => $provider,
            'model' => $model,
        ]]);
    }

    public function upsertMany(array $rows): void
    {
        foreach ($rows as $row) {
            $normalized = Vector::normalize($row['vector']);

            $this->query()->updateOrInsert(
                ['id' => $row['id']],
                [
                    'state' => $row['meta']['state'] ?? null,
                    'meta' => json_encode($row['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'vector' => Vector::pack($normalized),
                    'signature' => Vector::signature($normalized),
                    'dims' => count($normalized),
                    'provider' => $row['provider'] ?? null,
                    'model' => $row['model'] ?? null,
                    'content_hash' => $row['contentHash'],
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function has(string $id, string $contentHash): bool
    {
        return $this->query()
            ->where('id', $id)
            ->where('content_hash', $contentHash)
            ->exists();
    }

    public function get(string $id): ?array
    {
        $row = $this->query()->where('id', $id)->first(['id', 'meta', 'vector']);

        return $row === null ? null : $this->hydrate($row);
    }

    public function getMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->query()
            ->whereIn('id', $ids)
            ->get(['id', 'meta', 'vector'])
            ->mapWithKeys(fn ($row) => [$row->id => $this->hydrate($row)])
            ->all();
    }

    public function search(array $vector, int $limit = 10, float $minSimilarity = 0.0, ?string $state = null): array
    {
        $query = Vector::normalize($vector);
        $signature = Vector::signature($query);

        $candidates = $this->candidateIds($signature, $state, null);

        return $this->rerank($query, $candidates, $limit, $minSimilarity);
    }

    public function neighbors(string $id, int $limit = 10, float $minSimilarity = 0.0, ?string $state = null): array
    {
        $subject = $this->get($id);

        if ($subject === null) {
            return [];
        }

        $signature = Vector::signature($subject['vector']);
        $candidates = $this->candidateIds($signature, $state, $id);

        return $this->rerank($subject['vector'], $candidates, $limit, $minSimilarity);
    }

    public function forget(string $id): void
    {
        $this->query()->where('id', $id)->delete();
    }

    /**
     * @return array{id: string, vector: array<int, float>, meta: array<string, mixed>}
     */
    private function hydrate(object $row): array
    {
        return [
            'id' => $row->id,
            'vector' => Vector::unpack($row->vector),
            'meta' => json_decode((string) $row->meta, true) ?: [],
        ];
    }

    /**
     * Narrow the corpus (optionally scoped to one state, optionally
     * excluding one id) down to the {@see self::CANDIDATE_POOL} ids nearest
     * the given signature by Hamming distance.
     *
     * Loads only `id` and `signature` for the scan -- a full state's worth of
     * 32-byte signatures is on the order of a megabyte, cheap to hold and
     * compare in one pass, unlike loading every full vector would be.
     *
     * @return array<int, string>
     */
    private function candidateIds(string $signature, ?string $state, ?string $exclude): array
    {
        $rows = $this->query()
            ->when($state !== null, fn ($q) => $q->where('state', strtoupper($state)))
            ->when($exclude !== null, fn ($q) => $q->where('id', '!=', $exclude))
            ->get(['id', 'signature']);

        $scored = [];

        foreach ($rows as $row) {
            $scored[] = ['id' => $row->id, 'distance' => Vector::hamming($signature, $row->signature)];
        }

        usort($scored, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return array_map(
            fn (array $row): string => $row['id'],
            array_slice($scored, 0, self::CANDIDATE_POOL)
        );
    }

    /**
     * Score a small candidate set by exact dot product against a
     * (normalized) query vector, and return the top matches.
     *
     * @param  array<int, float>  $query
     * @param  array<int, string>  $candidateIds
     * @return array<int, array{id: string, score: float, meta: array<string, mixed>}>
     */
    private function rerank(array $query, array $candidateIds, int $limit, float $minSimilarity): array
    {
        if ($candidateIds === []) {
            return [];
        }

        $rows = $this->query()
            ->whereIn('id', $candidateIds)
            ->get(['id', 'meta', 'vector']);

        $scored = [];

        foreach ($rows as $row) {
            $vector = Vector::unpack($row->vector);

            if ($vector === []) {
                continue;
            }

            $score = Vector::dot($query, $vector);

            if ($score >= $minSimilarity) {
                $scored[] = [
                    'id' => $row->id,
                    'score' => round($score, 6),
                    'meta' => json_decode((string) $row->meta, true) ?: [],
                ];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    protected function query(): Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }
}
