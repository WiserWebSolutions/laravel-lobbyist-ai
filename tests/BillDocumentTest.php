<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests;

use WiserWebSolutions\Lobbyist\Ai\Support\BillDocument;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;

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
}
