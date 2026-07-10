<?php

namespace WiserWebSolutions\Lobbyist\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class BillClassifierAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Controlled subject vocabulary. Kept deliberately small and stable so
     * classifications are comparable and filterable across states.
     *
     * @var array<int, string>
     */
    public const SUBJECTS = [
        'agriculture', 'budget', 'civil_rights', 'commerce', 'crime', 'education',
        'elections', 'energy', 'environment', 'finance', 'government', 'health',
        'housing', 'immigration', 'labor', 'military', 'public_safety', 'taxation',
        'technology', 'transportation', 'other',
    ];

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a legislative classifier. Given a bill's details, categorize it.

        - subjects: one or more subjects from the allowed list that the bill primarily
          concerns. Choose the most specific applicable subjects; use "other" only when
          none fit.
        - tags: 1-6 short free-form topical tags (lowercase, e.g. "school funding",
          "data privacy") that describe the specific matter more precisely than the
          subject list.
        - impact: your best assessment of the bill's likely breadth of impact
          (low, medium, or high) based only on the provided details.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subjects' => $schema->array()
                ->items($schema->string()->enum(self::SUBJECTS))
                ->required(),
            'tags' => $schema->array()
                ->items($schema->string())
                ->required(),
            'impact' => $schema->string()
                ->enum(['low', 'medium', 'high'])
                ->required(),
        ];
    }
}
