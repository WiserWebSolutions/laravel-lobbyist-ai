<?php

namespace WiserWebSolutions\Lobbyist\Ai\Contracts;

/**
 * Pluggable storage for bill embeddings. The default implementation keeps
 * vectors in the application's database and scores similarity in PHP, but any
 * backend (pgvector, a provider vector store, etc.) can implement this.
 */
interface EmbeddingStore
{
    /**
     * Insert or update the embedding for a document.
     *
     * @param  array<int, float>  $vector
     * @param  array<string, mixed>  $meta  Recognized keys include: state, bill_number, title, url.
     */
    public function upsert(string $id, array $vector, array $meta, string $contentHash, ?string $provider = null, ?string $model = null): void;

    /**
     * Insert or update several embeddings in one call.
     *
     * Each row: {id: string, vector: float[], meta: array, contentHash: string,
     * provider?: string, model?: string}. Equivalent to calling
     * {@see self::upsert()} once per row, but lets a store batch the writes.
     *
     * @param  array<int, array{id: string, vector: array<int, float>, meta: array<string, mixed>, contentHash: string, provider?: string, model?: string}>  $rows
     */
    public function upsertMany(array $rows): void;

    /**
     * Whether a document with this id is already stored at the given content hash.
     */
    public function has(string $id, string $contentHash): bool;

    /**
     * Fetch one stored document by id, or null if it is not stored.
     *
     * @return array{id: string, vector: array<int, float>, meta: array<string, mixed>}|null
     */
    public function get(string $id): ?array;

    /**
     * Fetch several stored documents by id at once, keyed by id. An id with
     * nothing stored is simply absent from the result, not null-valued.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array{id: string, vector: array<int, float>, meta: array<string, mixed>}>
     */
    public function getMany(array $ids): array;

    /**
     * Return the most similar documents to the given query vector.
     *
     * @param  array<int, float>  $vector
     * @return array<int, array{id: string, score: float, meta: array<string, mixed>}>
     */
    public function search(array $vector, int $limit = 10, float $minSimilarity = 0.0, ?string $state = null): array;

    /**
     * Return the documents most similar to an already-indexed document,
     * identified by its id -- distinct from {@see self::search()}, which
     * takes a query vector rather than something already in the store.
     *
     * The subject document never appears in its own results.
     *
     * @return array<int, array{id: string, score: float, meta: array<string, mixed>}>
     */
    public function neighbors(string $id, int $limit = 10, float $minSimilarity = 0.0, ?string $state = null): array;

    /**
     * Remove a document from the store.
     */
    public function forget(string $id): void;
}
