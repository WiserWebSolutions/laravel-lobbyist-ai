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
            // Binary float32 (see Support\Vector::pack()), not JSON: a 256-dim
            // vector packs to 1 KB versus ~15 KB as a JSON array of PHP floats,
            // which is what makes reading a whole state's corpus into memory for
            // a similarity pass affordable rather than a multi-hundred-MB decode.
            $table->binary('vector');
            // A 256-bit sign-bit summary of the vector (Support\Vector::signature()),
            // compared by cheap Hamming distance to prefilter a large corpus down to
            // a few hundred candidates before the exact dot-product rerank. Exhaustive
            // exact scoring across a full state's bills is not viable in PHP.
            $table->binary('signature');
            $table->unsignedSmallInteger('dims');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
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
