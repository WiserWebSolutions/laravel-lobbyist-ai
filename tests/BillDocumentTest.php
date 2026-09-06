<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests;

use WiserWebSolutions\Lobbyist\Ai\Support\BillDocument;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\SponsorType;

class BillDocumentTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function bill(array $meta = []): Bill
    {
        return new Bill(meta: array_merge([
            'id' => 1,
            'number' => 'HB100',
            'title' => 'An act concerning stormwater',
            'state' => 'PA',
            'status' => 'Introduced',
        ], $meta));
    }

    private function legislator(string $name): Legislator
    {
        return new Legislator(meta: ['id' => 1, 'name' => $name, 'state' => 'PA']);
    }

    /**
     * A sponsor the way LegiscanMapper::sponsor() actually builds one: a
     * Legislator DTO whose meta carries sponsor_type/sponsor_order alongside
     * the person fields, since neither is a first-class DTO property.
     */
    private function sponsor(string $name, SponsorType $type, int $order, ?string $party = null, ?string $district = null): Legislator
    {
        return new Legislator(meta: [
            'id' => 1, 'name' => $name, 'state' => 'PA', 'party' => $party, 'district' => $district,
            'sponsor_type' => $type, 'sponsor_order' => $order,
        ]);
    }

    public function test_it_renders_sponsors_from_normalized_dtos(): void
    {
        // What a mapper produces now that sponsors are normalized. Casting one
        // of these to a string is fatal, which is how this was found.
        $document = BillDocument::forBill($this->bill([
            'sponsors' => [$this->legislator('Dana Whitfield'), $this->legislator('Marcus Reyes')],
        ]));

        $this->assertStringContainsString('Sponsors: Dana Whitfield, Marcus Reyes', $document);
    }

    public function test_it_renders_sponsors_from_a_legislator_collection(): void
    {
        $document = BillDocument::forBill($this->bill([
            'sponsors' => new LegislatorCollection([$this->legislator('Dana Whitfield')]),
        ]));

        $this->assertStringContainsString('Sponsors: Dana Whitfield', $document);
    }

    public function test_it_still_renders_sponsors_from_a_raw_payload(): void
    {
        $document = BillDocument::forBill($this->bill([
            'sponsors' => [['name' => 'Dana Whitfield'], ['last_name' => 'Reyes']],
        ]));

        $this->assertStringContainsString('Sponsors: Dana Whitfield, Reyes', $document);
    }

    public function test_it_renders_sponsors_given_as_plain_strings(): void
    {
        $document = BillDocument::forBill($this->bill([
            'sponsors' => ['Dana Whitfield', 'Marcus Reyes'],
        ]));

        $this->assertStringContainsString('Sponsors: Dana Whitfield, Marcus Reyes', $document);
    }

    public function test_a_bill_without_sponsors_renders_without_the_line(): void
    {
        $this->assertStringNotContainsString('Sponsors:', BillDocument::forBill($this->bill()));
    }

    public function test_it_separates_the_primary_sponsor_from_co_sponsors(): void
    {
        $document = BillDocument::forBill($this->bill([
            'sponsors' => [
                $this->sponsor('Marcus Reyes', SponsorType::CoSponsor, 2, party: 'R'),
                $this->sponsor('Dana Whitfield', SponsorType::Primary, 1, party: 'D', district: '12'),
            ],
        ]));

        $this->assertStringContainsString('Primary Sponsor: Dana Whitfield (D-12)', $document);
        $this->assertStringContainsString('Co-Sponsor: Marcus Reyes (R)', $document);
    }

    public function test_it_pluralizes_a_group_with_more_than_one_sponsor(): void
    {
        $document = BillDocument::forBill($this->bill([
            'sponsors' => [
                $this->sponsor('Dana Whitfield', SponsorType::Primary, 1),
                $this->sponsor('Marcus Reyes', SponsorType::CoSponsor, 2),
                $this->sponsor('Alice Johnson', SponsorType::CoSponsor, 3),
            ],
        ]));

        $this->assertStringContainsString('Co-Sponsors: Marcus Reyes, Alice Johnson', $document);
    }

    public function test_a_sponsor_with_no_reported_party_shows_no_party(): void
    {
        $document = BillDocument::forBill($this->bill([
            'sponsors' => [$this->sponsor('Dana Whitfield', SponsorType::Primary, 1)],
        ]));

        $this->assertStringContainsString('Primary Sponsor: Dana Whitfield', $document);
        $this->assertStringNotContainsString('(O)', $document);
        $this->assertStringNotContainsString('Whitfield (', $document);
    }

    public function test_sponsors_with_no_sponsor_type_still_render_the_flat_line(): void
    {
        // Unchanged behavior for a DTO built by something other than
        // LegiscanMapper::sponsor(), which never sets sponsor_type.
        $document = BillDocument::forBill($this->bill([
            'sponsors' => [$this->legislator('Dana Whitfield'), $this->legislator('Marcus Reyes')],
        ]));

        $this->assertStringContainsString('Sponsors: Dana Whitfield, Marcus Reyes', $document);
        $this->assertStringNotContainsString('Primary Sponsor', $document);
    }

    public function test_it_renders_action_history_nested_under_raw(): void
    {
        // The shape LegiscanMapper::bill() actually produces: the untouched
        // driver payload preserved under `raw`, not promoted to the top
        // level. This is the regression test for that gap.
        $document = BillDocument::forBill($this->bill([
            'raw' => ['history' => [['date' => '2026-01-08', 'action' => 'Referred to Education']]],
        ]));

        $this->assertStringContainsString('Action history:', $document);
        $this->assertStringContainsString('2026-01-08 — Referred to Education', $document);
    }

    public function test_a_top_level_history_still_takes_priority_over_raw(): void
    {
        $document = BillDocument::forBill($this->bill([
            'history' => [['date' => '2026-02-01', 'action' => 'Top level']],
            'raw' => ['history' => [['date' => '2026-01-08', 'action' => 'Nested']]],
        ]));

        $this->assertStringContainsString('Top level', $document);
        $this->assertStringNotContainsString('Nested', $document);
    }

    public function test_it_renders_votes(): void
    {
        $document = BillDocument::forBill($this->bill([
            'votes' => [new Vote(meta: [
                'id' => 1, 'chamber' => Chamber::House, 'date' => '2026-03-01',
                'description' => 'Third consideration', 'yea' => 120, 'nay' => 80, 'passed' => true,
            ])],
        ]));

        $this->assertStringContainsString('Votes:', $document);
        $this->assertStringContainsString('House vote on 2026-03-01', $document);
        $this->assertStringContainsString('120 yea, 80 nay', $document);
        $this->assertStringContainsString('passed', $document);
    }

    public function test_a_bill_without_votes_renders_without_the_section(): void
    {
        $this->assertStringNotContainsString('Votes:', BillDocument::forBill($this->bill()));
    }

    public function test_it_still_renders_the_standalone_vote_document_unchanged(): void
    {
        $document = BillDocument::forVote(new Vote(meta: [
            'id' => 1, 'chamber' => Chamber::House, 'date' => '2026-03-01',
            'description' => 'Third consideration', 'yea' => 120, 'nay' => 80, 'passed' => true,
        ]));

        $this->assertSame(
            "Vote (House) on 2026-03-01\nDescription: Third consideration\nTally: 120 yea, 80 nay\nResult: passed",
            $document,
        );
    }

    public function test_it_renders_official_documents_from_the_top_level(): void
    {
        $document = BillDocument::forBill($this->bill([
            'supplements' => [
                ['description' => 'Fiscal Note', 'type' => 'Fiscal Note', 'date' => '2026-01-15', 'url' => 'https://example.test/fn.pdf'],
            ],
        ]));

        $this->assertStringContainsString('Official documents', $document);
        $this->assertStringContainsString('citations only, not fetched', $document);
        $this->assertStringContainsString('Fiscal Note (Fiscal Note) — 2026-01-15: https://example.test/fn.pdf', $document);
    }

    public function test_it_renders_official_documents_nested_under_raw(): void
    {
        $document = BillDocument::forBill($this->bill([
            'raw' => ['supplements' => [
                ['description' => 'Actuarial Note', 'url' => 'https://example.test/an.pdf'],
            ]],
        ]));

        $this->assertStringContainsString('Actuarial Note', $document);
        $this->assertStringContainsString('https://example.test/an.pdf', $document);
    }

    public function test_a_bill_without_official_documents_renders_without_the_section(): void
    {
        $this->assertStringNotContainsString('Official documents', BillDocument::forBill($this->bill()));
    }

    // -----------------------------------------------------------------
    // forEmbedding() -- a narrower, similarity-shaped rendering
    // -----------------------------------------------------------------

    public function test_for_embedding_includes_number_title_and_description(): void
    {
        $document = BillDocument::forEmbedding($this->bill([
            'description' => 'Establishes minimum stormwater management standards.',
        ]));

        $this->assertStringContainsString('HB100', $document);
        $this->assertStringContainsString('An act concerning stormwater', $document);
        $this->assertStringContainsString('Establishes minimum stormwater management standards.', $document);
    }

    public function test_for_embedding_excludes_status_and_last_action(): void
    {
        $document = BillDocument::forEmbedding($this->bill([
            'status' => 'Introduced',
            'last_action' => 'Referred to APPROPRIATIONS',
        ]));

        $this->assertStringNotContainsString('Status:', $document);
        $this->assertStringNotContainsString('Last action:', $document);
        $this->assertStringNotContainsString('APPROPRIATIONS', $document);
    }

    public function test_for_embedding_excludes_action_history(): void
    {
        $document = BillDocument::forEmbedding($this->bill([
            'raw' => ['history' => [['date' => '2026-01-08', 'action' => 'Referred to Education']]],
        ]));

        $this->assertStringNotContainsString('Action history', $document);
        $this->assertStringNotContainsString('Referred to Education', $document);
    }

    public function test_for_embedding_excludes_votes(): void
    {
        $document = BillDocument::forEmbedding($this->bill([
            'votes' => [new Vote(meta: [
                'id' => 1, 'chamber' => Chamber::House, 'date' => '2026-03-01',
                'description' => 'Third consideration', 'yea' => 120, 'nay' => 80, 'passed' => true,
            ])],
        ]));

        $this->assertStringNotContainsString('Votes:', $document);
        $this->assertStringNotContainsString('Third consideration', $document);
    }

    public function test_for_embedding_excludes_official_document_citations(): void
    {
        $document = BillDocument::forEmbedding($this->bill([
            'supplements' => [
                ['description' => 'Fiscal Note', 'url' => 'https://example.test/fn.pdf'],
            ],
        ]));

        $this->assertStringNotContainsString('Official documents', $document);
        $this->assertStringNotContainsString('Fiscal Note', $document);
    }

    public function test_for_embedding_excludes_sponsors_even_when_present(): void
    {
        // Sponsors matter for cosponsor-gap analysis, but not for what makes
        // two bills topically similar, so they stay out of the vector too.
        $document = BillDocument::forEmbedding($this->bill([
            'sponsors' => [$this->legislator('Dana Whitfield')],
        ]));

        $this->assertStringNotContainsString('Sponsors:', $document);
        $this->assertStringNotContainsString('Whitfield', $document);
    }

    public function test_for_embedding_includes_subjects_and_tags_only_when_passed(): void
    {
        $withoutClassification = BillDocument::forEmbedding($this->bill());
        $this->assertStringNotContainsString('Subjects:', $withoutClassification);
        $this->assertStringNotContainsString('Tags:', $withoutClassification);

        $withClassification = BillDocument::forEmbedding(
            $this->bill(),
            subjects: ['Education', 'Public Health'],
            tags: ['school-funding'],
        );

        $this->assertStringContainsString('Subjects: Education, Public Health', $withClassification);
        $this->assertStringContainsString('Tags: school-funding', $withClassification);
    }

    public function test_for_embedding_ignores_blank_subjects_and_tags(): void
    {
        $document = BillDocument::forEmbedding($this->bill(), subjects: ['', 'Education'], tags: ['']);

        $this->assertStringContainsString('Subjects: Education', $document);
        $this->assertStringNotContainsString('Tags:', $document);
    }
}
