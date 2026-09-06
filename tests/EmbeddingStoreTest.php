<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests;

use WiserWebSolutions\Lobbyist\Ai\Contracts\EmbeddingStore;
use WiserWebSolutions\Lobbyist\Ai\Support\Vector;

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

    public function test_binary_round_trip_preserves_values_within_tolerance(): void
    {
        $store = $this->store();
        $vector = [0.12345, -0.98765, 0.5, -0.25, 0.0];

        $store->upsert('a', $vector, ['state' => 'PA'], 'h1');

        $stored = $store->get('a');
        $normalized = Vector::normalize($vector);

        $this->assertNotNull($stored);
        $this->assertCount(count($vector), $stored['vector']);
        foreach ($normalized as $i => $value) {
            $this->assertEqualsWithDelta($value, $stored['vector'][$i], 1e-6);
        }
    }

    public function test_upsert_normalizes_before_storage(): void
    {
        $store = $this->store();
        $store->upsert('a', [3.0, 4.0], ['state' => 'PA'], 'h1');

        $stored = $store->get('a');
        $normSq = array_sum(array_map(fn ($v) => $v * $v, $stored['vector']));

        // float32 round-trip precision, not float64 -- see Vector::pack().
        $this->assertEqualsWithDelta(1.0, $normSq, 1e-6);
    }

    public function test_get_and_get_many(): void
    {
        $store = $this->store();
        $store->upsert('a', [1.0, 0.0], ['state' => 'PA', 'bill_number' => 'HB1'], 'h1');
        $store->upsert('b', [0.0, 1.0], ['state' => 'PA', 'bill_number' => 'SB2'], 'h2');

        $this->assertNull($store->get('missing'));
        $this->assertSame('HB1', $store->get('a')['meta']['bill_number']);

        $many = $store->getMany(['a', 'b', 'missing']);

        $this->assertCount(2, $many);
        $this->assertSame('HB1', $many['a']['meta']['bill_number']);
        $this->assertSame('SB2', $many['b']['meta']['bill_number']);
        $this->assertArrayNotHasKey('missing', $many);
    }

    public function test_upsert_many_is_equivalent_to_repeated_upsert(): void
    {
        $store = $this->store();

        $store->upsertMany([
            ['id' => 'a', 'vector' => [1.0, 0.0], 'meta' => ['state' => 'PA', 'bill_number' => 'HB1'], 'contentHash' => 'h1'],
            ['id' => 'b', 'vector' => [0.0, 1.0], 'meta' => ['state' => 'PA', 'bill_number' => 'SB2'], 'contentHash' => 'h2', 'provider' => 'gemini', 'model' => 'gemini-embedding-2'],
        ]);

        $this->assertTrue($store->has('a', 'h1'));
        $this->assertTrue($store->has('b', 'h2'));
        $this->assertSame('HB1', $store->get('a')['meta']['bill_number']);
    }

    public function test_neighbors_excludes_the_subject_document(): void
    {
        $store = $this->store();
        $store->upsert('a', [1.0, 0.0, 0.0], ['state' => 'PA', 'bill_number' => 'HB1'], 'h1');
        $store->upsert('b', [0.95, 0.05, 0.0], ['state' => 'PA', 'bill_number' => 'HB2'], 'h2');
        $store->upsert('c', [0.0, 0.0, 1.0], ['state' => 'PA', 'bill_number' => 'HB3'], 'h3');

        $results = $store->neighbors('a', limit: 10, minSimilarity: 0.0, state: 'PA');

        $this->assertCount(2, $results);
        $this->assertSame('b', $results[0]['id']);
        $this->assertNotContains('a', array_column($results, 'id'));
    }

    public function test_neighbors_on_missing_id_returns_empty(): void
    {
        $store = $this->store();

        $this->assertSame([], $store->neighbors('missing'));
    }

    /**
     * The Hamming prefilter is a candidate generator, not the final ranking --
     * this proves it does not drop the true best match before the exact
     * dot-product rerank gets a chance to score it, across a corpus larger
     * than the candidate pool would be trivially safe for.
     */
    public function test_signature_prefilter_does_not_drop_the_true_best_match(): void
    {
        $store = $this->store();
        $dims = 32;

        $target = array_fill(0, $dims, 0.0);
        $target[0] = 1.0;
        $store->upsert('target', $target, ['state' => 'PA'], 'h-target');

        $bestMatch = $target;
        $bestMatch[1] = 0.05;
        $store->upsert('best', $bestMatch, ['state' => 'PA'], 'h-best');

        // A large field of unrelated, effectively random vectors.
        mt_srand(42);
        for ($i = 0; $i < 300; $i++) {
            $vector = [];
            for ($d = 0; $d < $dims; $d++) {
                $vector[] = (mt_rand(-1000, 1000) / 1000);
            }
            $store->upsert("noise-{$i}", $vector, ['state' => 'PA'], "h-noise-{$i}");
        }

        $results = $store->neighbors('target', limit: 5, minSimilarity: 0.0, state: 'PA');

        $this->assertSame('best', $results[0]['id']);
    }
}
