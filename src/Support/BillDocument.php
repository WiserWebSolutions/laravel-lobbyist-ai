<?php

namespace WiserWebSolutions\Lobbyist\Ai\Support;

use WiserWebSolutions\Lobbyist\Data\Bill;
use WiserWebSolutions\Lobbyist\Data\Vote;

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

        $tallies = array_filter([
            'yea' => $vote->yea,
            'nay' => $vote->nay,
            'not voting' => $vote->notVoting,
            'absent' => $vote->absent,
        ], fn ($v) => $v !== null);

        if ($tallies !== []) {
            $lines[] = 'Tally: '.implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($tallies), $tallies));
        }
        if ($vote->passed !== null) {
            $lines[] = 'Result: '.($vote->passed ? 'passed' : 'failed');
        }

        return implode("\n", $lines);
    }

    /**
     * Best-effort sponsor extraction from a driver's raw payload.
     *
     * @return array<int, string>
     */
    private static function sponsors(array $meta): array
    {
        $sponsors = $meta['sponsors'] ?? null;

        if (! is_array($sponsors) || $sponsors === []) {
            return [];
        }

        $names = array_map(function ($sponsor) {
            if (is_array($sponsor)) {
                return (string) ($sponsor['name'] ?? $sponsor['last_name'] ?? '');
            }

            return (string) $sponsor;
        }, $sponsors);

        $names = array_values(array_filter($names, fn ($n) => $n !== ''));

        return $names === [] ? [] : [implode(', ', array_slice($names, 0, 15))];
    }

    /**
     * Best-effort action-history extraction (palegis "actions" / LegiScan "history").
     *
     * @return array<int, string>
     */
    private static function actionHistory(array $meta): array
    {
        $actions = $meta['actions'] ?? $meta['history'] ?? null;

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
}
