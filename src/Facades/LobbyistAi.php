<?php

namespace WiserWebSolutions\Lobbyist\Ai\Facades;

use Illuminate\Support\Facades\Facade;
use WiserWebSolutions\Lobbyist\Ai\LobbyistAiManager;

/**
 * @method static array summarizeBill(\WiserWebSolutions\Lobbyist\Data\Bill $bill)
 * @method static array summarizeVote(\WiserWebSolutions\Lobbyist\Data\Vote $vote)
 * @method static array classifyBill(\WiserWebSolutions\Lobbyist\Data\Bill $bill)
 * @method static string ask(string $question, string|null $state = null)
 * @method static \Laravel\Ai\Responses\StreamableAgentResponse stream(string $question, string|null $state = null)
 * @method static array search(string $query, string|null $state = null, int|null $limit = null)
 * @method static array index(string $state)
 *
 * @see LobbyistAiManager
 */
class LobbyistAi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'lobbyist-ai';
    }
}
