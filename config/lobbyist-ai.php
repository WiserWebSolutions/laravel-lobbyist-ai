<?php

/*
|--------------------------------------------------------------------------
| Lobbyist AI Configuration
|--------------------------------------------------------------------------
|
| This package layers AI features (summaries, classification, natural-language
| Q&A, and semantic search) over the Lobbyist drivers using the Laravel AI SDK
| (laravel/ai). Configure the AI providers here. Text/reasoning runs on an
| Anthropic (Claude) model by default; embeddings must use a provider that
| offers an embeddings API (Anthropic does not) — OpenAI by default.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Text / Reasoning Model
    |--------------------------------------------------------------------------
    | Used for summaries, classification, and the Q&A agent.
    */
    'text' => [
        'provider' => env('LOBBYIST_AI_TEXT_PROVIDER', 'anthropic'),
        'model' => env('LOBBYIST_AI_TEXT_MODEL', 'claude-opus-4-8'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings Model (semantic search / RAG)
    |--------------------------------------------------------------------------
    | Anthropic has no embeddings API, so this uses a different provider.
    | Leave model null to use the provider's configured default.
    */
    'embeddings' => [
        'provider' => env('LOBBYIST_AI_EMBED_PROVIDER', 'openai'),
        'model' => env('LOBBYIST_AI_EMBED_MODEL'),
        'dimensions' => (int) env('LOBBYIST_AI_EMBED_DIMENSIONS', 1536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval-Augmented Generation (semantic search)
    |--------------------------------------------------------------------------
    | The embedding store is pluggable. The default "database" store keeps
    | vectors in the default database connection and computes cosine similarity
    | in PHP — no Postgres/pgvector required. Swap in another EmbeddingStore
    | implementation (e.g. pgvector or a provider vector store) for large scale.
    */
    'rag' => [
        'store' => env('LOBBYIST_AI_RAG_STORE', 'database'),
        'connection' => env('LOBBYIST_AI_RAG_CONNECTION'), // null = default connection
        'table' => env('LOBBYIST_AI_RAG_TABLE', 'lobbyist_ai_bill_embeddings'),
        'min_similarity' => (float) env('LOBBYIST_AI_RAG_MIN_SIMILARITY', 0.35),
        'limit' => (int) env('LOBBYIST_AI_RAG_LIMIT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Caching
    |--------------------------------------------------------------------------
    | AI calls are the dominant cost, so summaries/classifications are cached.
    | (Embedding generation is additionally cached by the Laravel AI SDK.)
    */
    'cache' => [
        'enabled' => env('LOBBYIST_AI_CACHE_ENABLED', true),
        'store' => env('LOBBYIST_AI_CACHE_STORE', env('CACHE_STORE')),
        'ttl' => (int) env('LOBBYIST_AI_CACHE_TTL', 86400),
    ],
];
