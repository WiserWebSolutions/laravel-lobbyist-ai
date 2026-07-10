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
    public function upsert(string $id, array $vector, array $meta, string $contentHash): void;

    /**
     * Whether a document with this id is already stored at the given content hash.
     */
    public function has(string $id, string $contentHash): bool;

    /**
     * Return the most similar documents to the given query vector.
     *
     * @param  array<int, float>  $vector
     * @return array<int, array{id: string, score: float, meta: array<string, mixed>}>
     */
    public function search(array $vector, int $limit = 10, float $minSimilarity = 0.0, ?string $state = null): array;

    /**
     * Remove a document from the store.
     */
    public function forget(string $id): void;
}
