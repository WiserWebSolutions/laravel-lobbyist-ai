<?php

namespace WiserWebSolutions\Lobbyist\Ai\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;
use WiserWebSolutions\Lobbyist\Ai\Tools\BillLookupTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\BillsTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\RepresentativeLookupTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\RepresentativesTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\SessionsTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\VoteLookupTool;
use WiserWebSolutions\Lobbyist\Ai\Tools\VotesTool;

#[MaxSteps(12)]
class LegislativeAssistant implements Agent, HasTools
{
    use Promptable;

    /**
     * @param  string|null  $defaultState  Two-letter state to assume when the user doesn't name one.
     * @param  array<int, object>  $extraTools  Additional tools (e.g. semantic search).
     */
    public function __construct(
        public ?string $defaultState = null,
        protected array $extraTools = [],
    ) {}

    public function instructions(): Stringable|string
    {
        $state = $this->defaultState
            ? "Unless the user specifies otherwise, assume the state is \"{$this->defaultState}\"."
            : 'Ask the user which US state they mean if it is unclear.';

        return <<<PROMPT
        You are a legislative research assistant for United States federal and state
        legislatures. Answer questions about bills, votes, and elected representatives
        using the provided tools — do not answer from prior knowledge when a tool can
        get the current facts.

        {$state}

        Tools take a two-letter state code (e.g. "PA", "CA", "US" for federal). Not every
        state supports every operation: some can list and look up bills, others can only
        list them. If a tool reports that an operation is unsupported, tell the user
        plainly rather than guessing. Prefer the semantic search tool to find relevant
        bills by topic, then look up specifics. Cite bill numbers in your answers.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new BillsTool,
            new BillLookupTool,
            new VotesTool,
            new VoteLookupTool,
            new RepresentativesTool,
            new RepresentativeLookupTool,
            new SessionsTool,
            ...$this->extraTools,
        ];
    }
}
