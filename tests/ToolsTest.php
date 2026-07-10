<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests;

use Laravel\Ai\Tools\Request;
use WiserWebSolutions\Lobbyist\Ai\Tools\BillLookupTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\BillsTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\RepresentativesTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\VoteLookupTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\VotesTool;

class ToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDriver('pa');
    }

    private function invoke(object $tool, array $args): array
    {
        return json_decode((string) $tool->handle(new Request($args)), true);
    }

    public function test_bills_tool_lists_bills(): void
    {
        $result = $this->invoke(new BillsTool, ['state' => 'PA']);

        $this->assertCount(2, $result);
        $this->assertSame('HB100', $result[0]['number']);
        $this->assertSame('House', $result[0]['chamber']);
    }

    public function test_bill_lookup_tool_returns_details(): void
    {
        $result = $this->invoke(new BillLookupTool, ['state' => 'PA', 'identifier' => 'HB100']);

        $this->assertSame('HB100', $result['number']);
        $this->assertStringContainsString('cursive', $result['description']);
    }

    public function test_votes_tool_lists_votes(): void
    {
        $result = $this->invoke(new VotesTool, ['state' => 'PA']);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['passed']);
    }

    public function test_representatives_tool_lists_members(): void
    {
        $result = $this->invoke(new RepresentativesTool, ['state' => 'PA']);

        $this->assertSame('Jane Doe', $result[0]['name']);
        $this->assertSame('Democrat', $result[0]['party']);
    }

    public function test_unsupported_operation_returns_error_not_exception(): void
    {
        // FakeStateDriver supports listing votes but not vote lookup.
        $result = $this->invoke(new VoteLookupTool, ['state' => 'PA', 'identifier' => '55']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('does not support', $result['error']);
    }
}
