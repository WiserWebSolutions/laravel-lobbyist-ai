<?php

namespace WiserWebSolutions\Lobbyist\Ai;

use Illuminate\Support\ServiceProvider;
use WiserWebSolutions\Lobbyist\Ai\Commands\IndexBillsCommand;
use WiserWebSolutions\Lobbyist\Ai\Contracts\EmbeddingStore;
use WiserWebSolutions\Lobbyist\Ai\Stores\DatabaseEmbeddingStore;

class LobbyistAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lobbyist-ai.php', 'lobbyist-ai');

        $this->app->singleton('lobbyist-ai', fn ($app) => new LobbyistAiManager(
            $app['config']->get('lobbyist-ai', [])
        ));

        // Pluggable embedding store; the default keeps vectors in the default DB.
        $this->app->singleton(EmbeddingStore::class, function ($app) {
            $config = $app['config']->get('lobbyist-ai.rag', []);

            return match ($config['store'] ?? 'database') {
                'database' => new DatabaseEmbeddingStore(
                    connection: $config['connection'] ?? null,
                    table: $config['table'] ?? 'lobbyist_ai_bill_embeddings',
                ),
                default => throw new \InvalidArgumentException(
                    "Unsupported lobbyist-ai RAG store [{$config['store']}]."
                ),
            };
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lobbyist-ai.php' => config_path('lobbyist-ai.php'),
            ], 'lobbyist-ai-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'lobbyist-ai-migrations');

            $this->commands([
                IndexBillsCommand::class,
            ]);
        }
    }
}
