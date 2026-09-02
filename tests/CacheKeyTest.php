<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests;

use Laravel\Ai\Ai;
use WiserWebSolutions\Lobbyist\Ai\Agents\BillSummaryAgent;
use WiserWebSolutions\Lobbyist\Data\Bill;

class CacheKeyTest extends TestCase
{
    private function bill(?string $changeHash, string $title = 'An act'): Bill
    {
        return new Bill(meta: [
            'id' => 1,
            'number' => 'HB100',
            'title' => $title,
            'state' => 'PA',
            'change_hash' => $changeHash,
        ]);
    }

    public function test_a_revised_bill_is_not_served_the_old_summary(): void
    {
        config(['lobbyist-ai.cache.enabled' => true]);

        Ai::fakeAgent(BillSummaryAgent::class, [
            ['headline' => 'Before', 'summary' => 'The original.', 'key_points' => []],
            ['headline' => 'After', 'summary' => 'The amended version.', 'key_points' => []],
        ]);

        $first = $this->manager()->summarizeBill($this->bill('hash-one'));
        $second = $this->manager()->summarizeBill($this->bill('hash-two', 'An act, as amended'));

        // Keyed on lastActionDate this was impossible to get right: LegiScan's
        // getBill payload has no last-action date, so the key never moved and a
        // bill could be amended, passed and signed behind a stale summary.
        $this->assertSame('Before', $first['headline']);
        $this->assertSame('After', $second['headline']);
    }

    public function test_an_unchanged_bill_is_served_from_cache(): void
    {
        config(['lobbyist-ai.cache.enabled' => true]);

        Ai::fakeAgent(BillSummaryAgent::class, [
            ['headline' => 'Only once', 'summary' => 'The original.', 'key_points' => []],
        ]);

        // A second call with nothing changed must not reach the provider; the
        // fake has only one response queued, so it would fail if it did.
        $this->manager()->summarizeBill($this->bill('hash-one'));
        $again = $this->manager()->summarizeBill($this->bill('hash-one'));

        $this->assertSame('Only once', $again['headline']);
    }
}
