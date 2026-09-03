<?php

namespace WiserWebSolutions\Lobbyist\Ai\Support;

use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\Legislator;
use WiserWebSolutions\Lobbyist\Data\LegislatorCollection;
use WiserWebSolutions\Lobbyist\Data\Vote;
use WiserWebSolutions\Lobbyist\Legiscan\Support\LegiscanMapper;

/**
 * Renders normalized DTOs into compact plain-text documents suitable for LLM
 * prompts and embeddings. Shared by summaries, classification, and indexing so
 * the model always sees the same representation of a bill or vote.
 */
class BillDocument
{
    public static function forBill(Bill $bill): string
    {
        $lines = [];
        $chamber = $bill->chamber?->label();
        $lines[] = trim("Bill: {$bill->number} ({$bill->state->label()}".($chamber ? " {$chamber}" : '').')');

        if ($bill->title !== '') {
            $lines[] = "Title: {$bill->title}";
        }
        if ($bill->status !== '') {
            $lines[] = 'Status: '.$bill->status.($bill->statusDate ? ' (as of '.$bill->statusDate->toDateString().')' : '');
        }
        if ($bill->lastAction !== '') {
            $lines[] = 'Last action: '.$bill->lastAction.($bill->lastActionDate ? ' ('.$bill->lastActionDate->toDateString().')' : '');
        }
        if ($bill->description !== '') {
            $lines[] = "Description: {$bill->description}";
        }

        foreach (self::sponsors($bill->meta) as $sponsors) {
            $lines[] = 'Sponsors: '.$sponsors;
        }

        $history = self::actionHistory($bill->meta);
        if ($history !== []) {
            $lines[] = 'Action history:';
            foreach ($history as $entry) {
                $lines[] = '- '.$entry;
            }
        }

        $votes = $bill->votes();
        if ($votes->isNotEmpty()) {
            $lines[] = 'Votes:';
            foreach ($votes as $vote) {
                $lines[] = '- '.self::describeVote($vote);
            }
        }

        $documents = self::officialDocuments($bill->meta);
        if ($documents !== []) {
            // Citations, not content: nothing here has been fetched or read,
            // so the model must not describe one as though it had.
            $lines[] = 'Official documents (fiscal notes, analyses -- citations only, not fetched):';
            foreach ($documents as $entry) {
                $lines[] = '- '.$entry;
            }
        }

        return implode("\n", $lines);
    }

    public static function forVote(Vote $vote): string
    {
        $lines = [];
        $chamber = $vote->chamber?->label();
        $lines[] = 'Vote'.($chamber ? " ({$chamber})" : '').($vote->date ? ' on '.$vote->date->toDateString() : '');

        if ($vote->description !== '') {
            $lines[] = "Description: {$vote->description}";
        }
        if ($vote->billId !== null) {
            $lines[] = "Related bill id: {$vote->billId}";
        }

        $tally = self::voteTally($vote);
        if ($tally !== '') {
            $lines[] = 'Tally: '.$tally;
        }
        if ($vote->passed !== null) {
            $lines[] = 'Result: '.($vote->passed ? 'passed' : 'failed');
        }

        return implode("\n", $lines);
    }

    /**
     * One compact line summarizing a roll call, for the votes section of a
     * bill document -- distinct from {@see self::forVote()}'s own multi-line
     * rendering, which stays unchanged for the standalone vote document.
     * Both read the tally the same way, via {@see self::voteTally()}.
     */
    private static function describeVote(Vote $vote): string
    {
        $chamber = $vote->chamber?->label();
        $date = $vote->date?->toDateString();
        $tally = self::voteTally($vote);
        $result = $vote->passed === null ? null : ($vote->passed ? 'passed' : 'failed');

        $prefix = trim(($chamber ? "{$chamber} " : '').'vote'.($date ? " on {$date}" : ''));

        $details = implode(' — ', array_filter([
            $vote->description !== '' ? $vote->description : null,
            $tally,
            $result,
        ], fn ($part) => $part !== null && $part !== ''));

        return $details === '' ? $prefix : "{$prefix}: {$details}";
    }

    /**
     * @return array<string, int>
     */
    private static function voteTallies(Vote $vote): array
    {
        return array_filter([
            'yea' => $vote->yea,
            'nay' => $vote->nay,
            'not voting' => $vote->notVoting,
            'absent' => $vote->absent,
        ], fn ($v) => $v !== null);
    }

    private static function voteTally(Vote $vote): string
    {
        $tallies = self::voteTallies($vote);

        return $tallies === [] ? '' : implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($tallies), $tallies));
    }

    /**
     * Best-effort sponsor extraction.
     *
     * Three shapes occur in practice and all three have to work: normalized
     * Legislator DTOs, which is what a mapper produces now that sponsors are
     * mapped; raw payload arrays, from a driver response that was never
     * normalized; and plain strings. The unconditional string cast this used to
     * end with was fatal on the first of those.
     *
     * @return array<int, string>
     */
    private static function sponsors(array $meta): array
    {
        $sponsors = $meta['sponsors'] ?? null;

        if ($sponsors instanceof LegislatorCollection) {
            $sponsors = $sponsors->all();
        }

        if (! is_array($sponsors) || $sponsors === []) {
            return [];
        }

        $names = array_map(function ($sponsor) {
            if ($sponsor instanceof Legislator) {
                return $sponsor->name;
            }

            if (is_array($sponsor)) {
                return (string) ($sponsor['name'] ?? $sponsor['last_name'] ?? '');
            }

            return is_scalar($sponsor) ? (string) $sponsor : '';
        }, $sponsors);

        $names = array_values(array_filter($names, fn ($n) => $n !== ''));

        return $names === [] ? [] : [implode(', ', array_slice($names, 0, 15))];
    }

    /**
     * Best-effort action-history extraction (palegis "actions" / LegiScan "history").
     *
     * Checked at the top level of `meta` first, then falls back to
     * `meta['raw']` -- where a mapper that preserves the untouched driver
     * payload under `raw` (as {@see LegiscanMapper::bill()}
     * does) actually put it. Without the fallback this silently found
     * nothing on every bill produced by that mapper, despite it being the
     * production path.
     *
     * @return array<int, string>
     */
    private static function actionHistory(array $meta): array
    {
        $raw = is_array($meta['raw'] ?? null) ? $meta['raw'] : [];
        $actions = $meta['actions'] ?? $meta['history'] ?? $raw['actions'] ?? $raw['history'] ?? null;

        if (! is_array($actions)) {
            return [];
        }

        $out = [];
        foreach (array_slice($actions, 0, 25) as $action) {
            if (! is_array($action)) {
                continue;
            }

            $date = $action['date'] ?? '';
            $text = $action['full_action'] ?? $action['action'] ?? $action['fullAction'] ?? '';

            if ($text === '' && isset($action['verb'])) {
                $text = trim(($action['verb'] ?? '').' '.($action['committee'] ?? ''));
            }

            $line = trim(($date ? $date.' — ' : '').$text);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Best-effort fiscal note / analysis citation extraction (LegiScan
     * "supplements"). No core DTO models these -- unlike sponsors, texts and
     * votes, no source-agnostic shape has been needed elsewhere yet -- so
     * this reads the raw payload directly, the same way {@see
     * self::actionHistory()} does for a field with no dedicated accessor.
     *
     * Titles and links only: nothing here has been fetched, so there is
     * nothing to summarize beyond citing that it exists.
     *
     * @return array<int, string>
     */
    private static function officialDocuments(array $meta): array
    {
        $raw = is_array($meta['raw'] ?? null) ? $meta['raw'] : [];
        $documents = $meta['supplements'] ?? $raw['supplements'] ?? null;

        if (! is_array($documents)) {
            return [];
        }

        $out = [];
        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }

            $url = $document['state_link'] ?? $document['url'] ?? '';
            if (! is_string($url) || $url === '') {
                continue;
            }

            $title = (string) ($document['description'] ?? $document['title'] ?? 'Document');
            $type = isset($document['type']) && $document['type'] !== '' ? " ({$document['type']})" : '';
            $date = isset($document['date']) && $document['date'] !== '' && $document['date'] !== '0000-00-00'
                ? ' — '.$document['date']
                : '';

            $out[] = "{$title}{$type}{$date}: {$url}";
        }

        return $out;
    }
}
