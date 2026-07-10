<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests\Fakes;

use WiserWebSolutions\Lobbyist\Contracts\Providers\BillLookup;
use WiserWebSolutions\Lobbyist\Contracts\Providers\BillProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\RepresentativeProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\SessionProvider;
use WiserWebSolutions\Lobbyist\Contracts\Providers\VoteProvider;
use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\BillCollection;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;
use WiserWebSolutions\Lobbyist\Data\Session;
use WiserWebSolutions\Lobbyist\Data\SessionCollection;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Data\VoteCollection;
use WiserWebSolutions\Lobbyist\Enums\Chamber;
use WiserWebSolutions\Lobbyist\Enums\Party;
use WiserWebSolutions\Lobbyist\Enums\StateEnum;
use WiserWebSolutions\Lobbyist\Support\AbstractDriver;

/**
 * A fully-featured fake driver returning sample DTOs — used to exercise the AI
 * tools and indexing without hitting a real data source. Supports bill listing
 * + lookup, votes, representatives, and sessions (but not vote/rep lookup, so
 * the "unsupported" guards can be tested too).
 */
class FakeStateDriver extends AbstractDriver implements BillLookup, BillProvider, RepresentativeProvider, SessionProvider, VoteProvider
{
    public function bills(): BillCollection
    {
        return new BillCollection([
            new Bill(meta: [
                'id' => 100, 'number' => 'HB100', 'title' => 'An Act concerning school cursive instruction',
                'description' => 'Requires cursive handwriting instruction in public schools.',
                'state' => StateEnum::PA, 'chamber' => Chamber::House, 'status' => 'Referred',
                'last_action' => 'Referred to EDUCATION', 'last_action_date' => '2025-01-08',
                'url' => 'https://example.test/HB100',
                'actions' => [['date' => '2025-01-08', 'full_action' => 'Referred to EDUCATION']],
            ]),
            new Bill(meta: [
                'id' => 200, 'number' => 'SB200', 'title' => 'An Act concerning transportation funding',
                'description' => 'Adjusts the motor license fund.',
                'state' => StateEnum::PA, 'chamber' => Chamber::Senate, 'status' => 'Introduced',
                'last_action' => 'Introduced', 'last_action_date' => '2025-02-01',
                'url' => 'https://example.test/SB200',
            ]),
        ]);
    }

    public function bill(string|int $identifier): Bill
    {
        return $this->bills()->first(
            fn (Bill $bill) => strcasecmp($bill->number, (string) $identifier) === 0,
        ) ?? new Bill(meta: ['id' => $identifier, 'number' => (string) $identifier]);
    }

    public function votes(): VoteCollection
    {
        return new VoteCollection([
            new Vote(meta: [
                'id' => 55, 'bill_id' => 100, 'chamber' => Chamber::House, 'date' => '2025-03-01',
                'description' => 'Third consideration of HB100', 'yea' => 120, 'nay' => 80, 'passed' => true,
            ]),
        ]);
    }

    public function representatives(): LegislatorCollection
    {
        return new LegislatorCollection([
            new Legislator(meta: [
                'id' => 1, 'name' => 'Jane Doe', 'party' => Party::Democrat,
                'chamber' => Chamber::House, 'district' => '174', 'state' => StateEnum::PA,
            ]),
        ]);
    }

    public function sessions(): SessionCollection
    {
        return new SessionCollection([
            new Session(meta: ['id' => 1, 'name' => '2025-2026', 'title' => '2025-2026 Regular Session', 'state' => StateEnum::PA]),
        ]);
    }
}
