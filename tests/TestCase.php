<?php

namespace WiserWebSolutions\Lobbyist\Ai\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use WiserWebSolutions\Lobbyist\Ai\LobbyistAiManager;
use WiserWebSolutions\Lobbyist\Ai\LobbyistAiServiceProvider;
use WiserWebSolutions\Lobbyist\Ai\Tests\Fakes\FakeStateDriver;
use WiserWebSolutions\Lobbyist\LobbyistServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            LobbyistServiceProvider::class,
            AiServiceProvider::class,
            LobbyistAiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Exercise the real code paths (not cached) in tests.
        $app['config']->set('lobbyist-ai.cache.enabled', false);

        // Dummy provider credentials so provider resolution works under fakes.
        $app['config']->set('ai.providers.anthropic.key', 'test-anthropic-key');
        $app['config']->set('ai.providers.openai.key', 'test-openai-key');
    }

    /**
     * Register the sample fake driver under the given state abbreviation.
     */
    protected function fakeDriver(string $state = 'pa'): void
    {
        $this->app->make('lobbyist')->extend(
            strtolower($state),
            fn () => new FakeStateDriver,
        );
    }

    protected function manager(): LobbyistAiManager
    {
        return $this->app->make('lobbyist-ai');
    }
}
