<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->string('id')->primary();       // e.g. "PA:20250HB0017"
            $table->string('state')->nullable()->index();
            $table->longText('meta');               // JSON: bill_number, title, url, ...
            $table->longText('vector');             // JSON array of floats
            $table->string('content_hash', 32);     // md5 of the source document (incremental indexing)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return Config::get('lobbyist-ai.rag.table', 'lobbyist_ai_bill_embeddings');
    }
};
