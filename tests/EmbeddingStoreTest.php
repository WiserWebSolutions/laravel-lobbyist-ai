<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests;

use WiserWebSolutions\Lobbyist\Ai\Contracts\EmbeddingStore;

class EmbeddingStoreTest extends TestCase
{
    private function store(): EmbeddingStore
    {
        return $this->app->make(EmbeddingStore::class);
    }

    public function test_upsert_and_cosine_ranking(): void
    {
        $store = $this->store();

        $store->upsert('a', [1.0, 0.0, 0.0], ['state' => 'PA', 'bill_number' => 'HB1', 'title' => 'Alpha'], 'h1');
        $store->upsert('b', [0.0, 1.0, 0.0], ['state' => 'PA', 'bill_number' => 'SB2', 'title' => 'Beta'], 'h2');

        $results = $store->search([0.9, 0.1, 0.0], limit: 10, minSimilarity: 0.0);

        $this->assertSame('a', $results[0]['id']);
        $this->assertSame('HB1', $results[0]['meta']['bill_number']);
        $this->assertGreaterThan($results[1]['score'], $results[0]['score']);
    }

    public function test_content_hash_gates_reindexing(): void
    {
        $store = $this->store();
        $store->upsert('a', [1.0, 0.0], ['state' => 'PA'], 'hash-1');

        $this->assertTrue($store->has('a', 'hash-1'));
        $this->assertFalse($store->has('a', 'hash-2'));
    }

    public function test_min_similarity_and_state_filter(): void
    {
        $store = $this->store();
        $store->upsert('pa', [1.0, 0.0], ['state' => 'PA'], 'h1');
        $store->upsert('ca', [1.0, 0.0], ['state' => 'CA'], 'h2');

        $this->assertCount(1, $store->search([1.0, 0.0], state: 'PA'));
        $this->assertSame([], $store->search([0.0, 1.0], minSimilarity: 0.5)); // orthogonal → excluded
    }

    public function test_upsert_updates_in_place(): void
    {
        $store = $this->store();
        $store->upsert('a', [1.0, 0.0], ['state' => 'PA'], 'h1');
        $store->upsert('a', [0.0, 1.0], ['state' => 'PA'], 'h2');

        $this->assertTrue($store->has('a', 'h2'));
        $this->assertFalse($store->has('a', 'h1'));
    }
}
