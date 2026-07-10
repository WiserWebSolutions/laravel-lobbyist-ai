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
        single bill (its number, title, status, description, and action history).

        Produce a neutral, factual summary for a general audience:
        - headline: a short, plain headline (no more than ~12 words).
        - summary: 2-4 sentences in plain language explaining what the bill does and
          where it stands. Avoid legislative jargon; do not editorialize or predict.
        - key_points: 2-5 short bullet strings covering the most important specifics.

        Only use information present in the provided details. If something is unknown,
        omit it rather than guessing.
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
