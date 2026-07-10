# Laravel Lobbyist — AI

An optional AI layer for [`wiserwebsolutions/laravel-lobbyist`](https://github.com/wiserwebsolutions/laravel-lobbyist),
built on the first-party [Laravel AI SDK](https://laravel.com/ai) (`laravel/ai`). It
**summarizes** and **classifies** bills, answers natural-language questions with a
tool-using **agent**, and provides **semantic search** over an indexed bill corpus —
all on top of the existing `Lobbyist::state(...)` driver surface.

It is a *consumer* of the Lobbyist drivers (not a driver itself), so core stays
dependency-free.

## Installation

```bash
composer require wiserwebsolutions/laravel-lobbyist-ai
php artisan vendor:publish --tag=lobbyist-ai-config
php artisan vendor:publish --tag=lobbyist-ai-migrations
php artisan migrate
```

Requires Laravel 13 (for `laravel/ai`) and at least one Lobbyist driver
(`laravel-lobbyist-legiscan` and/or `laravel-palegis`).

### Configuration

By default this package defers entirely to your `laravel/ai` configuration
(`config/ai.php`) — text (summaries, classification, Q&A) uses `ai.default`,
embeddings use `ai.default_for_embeddings`, and each uses that provider's own
default model. Set up `laravel/ai` as normal and this package follows suit.

To point this package at a *different* provider than the rest of your app
(without changing your `ai.default`), override it here:

```dotenv
LOBBYIST_AI_TEXT_PROVIDER=anthropic
LOBBYIST_AI_EMBED_PROVIDER=openai
LOBBYIST_AI_EMBED_DIMENSIONS=1536
```

See `config/lobbyist-ai.php` for cache and RAG options.

## Usage

```php
use WiserWebSolutions\Lobbyist\Ai\Facades\LobbyistAi;
use WiserWebSolutions\Lobbyist\Facades\Lobbyist;

// Summaries (structured: headline / summary / key_points), cached.
$bill = Lobbyist::state('CA')->bill('AB1');
$summary = LobbyistAi::summarizeBill($bill);

// Classification (controlled subjects + tags + impact).
$tags = LobbyistAi::classifyBill($bill);

// Natural-language Q&A — the agent calls the driver tools to get the facts.
$answer = LobbyistAi::ask('What education bills are moving in PA this session?', 'PA');

// Semantic search (after indexing).
$matches = LobbyistAi::search('cursive handwriting in schools', 'PA');
```

### Semantic search / RAG

Index a state's bills (incremental — unchanged bills are skipped):

```bash
php artisan lobbyist-ai:index PA
```

Embeddings are stored via a **pluggable `EmbeddingStore`**. The default `database`
store keeps vectors in your default database connection and scores cosine similarity
in PHP — **no Postgres/pgvector required**. For large corpora, bind a vector-native
implementation of `WiserWebSolutions\Lobbyist\Ai\Contracts\EmbeddingStore` instead.

## Capabilities & the driver contract

The Q&A agent's tools guard every call with the driver's `supports(Capability)` check,
so it degrades honestly: if a state's driver can't list votes or look up a bill by id,
the tool says so rather than inventing an answer.

## Testing

Tests use the Laravel AI SDK's fakes (`Ai::fakeAgent`, `Ai::fakeEmbeddings`) and a fake
Lobbyist driver, so they never hit the network:

```bash
composer install
vendor/bin/phpunit
```

## License

MIT © Daniel Wiser
