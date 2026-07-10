<?php

namespace WiserWebSolutions\Lobbyist\Ai\Stores;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use WiserWebSolutions\Lobbyist\Ai\Contracts\EmbeddingStore;

/**
 * Default embedding store: vectors live in a regular database table (JSON) on
 * the application's default connection, and cosine similarity is computed in
 * PHP. No Postgres/pgvector required. Suitable for the few-thousand-bills-per-
 * state scale; swap in a vector-native store for larger corpora.
 */
class DatabaseEmbeddingStore implements EmbeddingStore
{
    public function __construct(
        protected ?string $connection = null,
        protected string $table = 'lobbyist_ai_bill_embeddings',
    ) {}

    public function upsert(string $id, array $vector, array $meta, string $contentHash): void
    {
        $this->query()->updateOrInsert(
            ['id' => $id],
            [
                'state' => $meta['state'] ?? null,
                'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'vector' => json_encode($vector),
                'content_hash' => $contentHash,
                'updated_at' => now(),
            ],
        );
    }

    public function has(string $id, string $contentHash): bool
    {
        return $this->query()
            ->where('id', $id)
            ->where('content_hash', $contentHash)
            ->exists();
    }

    public function search(array $vector, int $limit = 10, float $minSimilarity = 0.0, ?string $state = null): array
    {
        $queryNorm = $this->norm($vector);

        if ($queryNorm === 0.0) {
            return [];
        }

        $rows = $this->query()
            ->when($state !== null, fn ($q) => $q->where('state', strtoupper($state)))
            ->get(['id', 'meta', 'vector']);

        $scored = [];

        foreach ($rows as $row) {
            $stored = json_decode($row->vector, true);

            if (! is_array($stored) || $stored === []) {
                continue;
            }

            $score = $this->cosine($vector, $queryNorm, $stored);

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

    public function forget(string $id): void
    {
        $this->query()->where('id', $id)->delete();
    }

    /**
     * Cosine similarity of the query (precomputed norm) against a stored vector.
     *
     * @param  array<int, float>  $query
     * @param  array<int, float>  $stored
     */
    protected function cosine(array $query, float $queryNorm, array $stored): float
    {
        $dot = 0.0;
        $storedNormSq = 0.0;
        $n = min(count($query), count($stored));

        for ($i = 0; $i < $n; $i++) {
            $dot += $query[$i] * $stored[$i];
        }

        foreach ($stored as $value) {
            $storedNormSq += $value * $value;
        }

        $denominator = $queryNorm * sqrt($storedNormSq);

        return $denominator === 0.0 ? 0.0 : $dot / $denominator;
    }

    /**
     * @param  array<int, float>  $vector
     */
    protected function norm(array $vector): float
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }

    protected function query(): Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }
}
