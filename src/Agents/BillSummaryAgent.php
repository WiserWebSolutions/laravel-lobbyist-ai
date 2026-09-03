<?php

namespace WiserWebSolutions\Lobbyist\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class BillSummaryAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a nonpartisan legislative analyst. You will be given the details of a
        single bill (its number, title, status, description, action history, recorded
        votes, and citations to any fiscal notes or analyses). You may also receive one
        or more attached documents containing the bill's full text -- when there is more
        than one, they are ordered oldest to newest, so the last one is the current
        version.

        Produce a neutral, factual, detailed summary for a general audience:
        - headline: a short, plain headline (no more than ~12 words).
        - summary: 2-4 sentences in plain language explaining what the bill does and
          where it stands. Avoid legislative jargon; do not editorialize or predict.
        - key_points: 2-5 short bullet strings covering the most important specifics.
          When the full text is attached, draw these from its actual substance --
          what it requires, changes, funds, or repeals -- not just the procedural
          facts already in the metadata.

        Only use information present in the provided details or attached documents. If
        something is unknown, omit it rather than guessing. Fiscal note and analysis
        citations are titles and links only, never fetched content -- do not describe
        one as though you had read it.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
            'key_points' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
