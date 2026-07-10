<?php

/*
|--------------------------------------------------------------------------
| Lobbyist AI Configuration
|--------------------------------------------------------------------------
|
| This package layers AI features (summaries, classification, natural-language
| Q&A, and semantic search) over the Lobbyist drivers using the Laravel AI SDK
| (laravel/ai). By default it defers entirely to your Laravel AI config
| (config/ai.php) — the "default" provider for text and "default_for_embeddings"
| provider for embeddings, each using that provider's own default model. The
| options below only need to be set if you want this package to use a
| different provider than the rest of your app.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Text / Reasoning Provider
    |--------------------------------------------------------------------------
    | Used for summaries, classification, and the Q&A agent. Leave null to use
    | Laravel AI's default provider (config('ai.default')) and its default
    | text model.
    */
    'text' => [
        'provider' => env('LOBBYIST_AI_TEXT_PROVIDER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings Provider (semantic search / RAG)
    |--------------------------------------------------------------------------
    | Leave null to use Laravel AI's default embeddings provider
    | (config('ai.default_for_embeddings')) and its default embedding model.
    */
    'embeddings' => [
        'provider' => env('LOBBYIST_AI_EMBED_PROVIDER'),
        'dimensions' => env('LOBBYIST_AI_EMBED_DIMENSIONS') !== null
            ? (int) env('LOBBYIST_AI_EMBED_DIMENSIONS')
            : null,
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
