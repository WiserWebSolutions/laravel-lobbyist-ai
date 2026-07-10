<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests;

use Laravel\Ai\Ai;
use WiserWebSolutions\Lobbyist\Ai\Agents\BillClassifierAgent;
use WiserWebSolutions\Lobbyist\Ai\Agents\BillSummaryAgent;
use WiserWebSolutions\Lobbyist\Ai\Agents\LegislativeAssistant;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

class AiFeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDriver('pa');
    }

    public function test_summarize_bill_returns_structured_summary(): void
    {
        Ai::fakeAgent(BillSummaryAgent::class, [
            [
                'headline' => 'Cursive handwriting mandate',
                'summary' => 'Requires cursive instruction in public schools.',
                'key_points' => ['Applies to public schools', 'Referred to Education'],
            ],
        ]);

        $bill = Lobbyist::state('PA')->bill('HB100');
        $result = $this->manager()->summarizeBill($bill);

        $this->assertSame('Cursive handwriting mandate', $result['headline']);
        $this->assertCount(2, $result['key_points']);
    }

    public function test_classify_bill_returns_subjects_and_impact(): void
    {
        Ai::fakeAgent(BillClassifierAgent::class, [
            ['subjects' => ['education'], 'tags' => ['cursive', 'curriculum'], 'impact' => 'medium'],
        ]);

        $bill = Lobbyist::state('PA')->bill('HB100');
        $result = $this->manager()->classifyBill($bill);

        $this->assertSame(['education'], $result['subjects']);
        $this->assertSame('medium', $result['impact']);
    }

    public function test_ask_returns_agent_text(): void
    {
        Ai::fakeAgent(LegislativeAssistant::class, [
            'HB100 requires cursive handwriting instruction in Pennsylvania public schools.',
        ]);

        $answer = $this->manager()->ask('What does HB100 do?', 'PA');

        $this->assertStringContainsString('cursive', $answer);
    }

    public function test_index_and_semantic_search(): void
    {
        // Deterministic embeddings: dimension 0 = "cursive", dimension 1 = "transport".
        Ai::fakeEmbeddings(fn ($prompt) => array_map(function (string $text) {
            $text = strtolower($text);

            return [
                str_contains($text, 'cursive') ? 1.0 : 0.0,
                str_contains($text, 'transport') ? 1.0 : 0.0,
                0.1,
            ];
        }, $prompt->inputs));

        $indexed = $this->manager()->index('PA');
        $this->assertSame(2, $indexed['indexed']);

        // Re-indexing skips unchanged bills.
        $again = $this->manager()->index('PA');
        $this->assertSame(0, $again['indexed']);
        $this->assertSame(2, $again['skipped']);

        $results = $this->manager()->search('cursive handwriting in schools', 'PA');

        $this->assertNotEmpty($results);
        $this->assertSame('HB100', $results[0]['meta']['bill_number']);
    }
}
