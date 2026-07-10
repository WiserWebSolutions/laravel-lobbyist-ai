<?php

namespace WiserWebSolutions\Lobbyist\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class VoteSummaryAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a nonpartisan legislative analyst. You will be given the details of a
        single roll-call vote (chamber, date, description, tallies, and result).

        Produce a neutral, factual summary:
        - headline: a short, plain headline (no more than ~12 words).
        - summary: 1-3 sentences describing what was voted on and the outcome, using
          the tallies provided. Do not editorialize. Only use the given information.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}
