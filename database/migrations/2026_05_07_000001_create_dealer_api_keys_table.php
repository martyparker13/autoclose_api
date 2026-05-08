<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            // SHA-256 hash of the raw key — the raw key is shown once and never stored
            $table->string('key_hash', 64)->unique();
            // First 8 chars of the raw key prefix, shown in the UI to identify the key
            $table->string('key_prefix', 8);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('dealer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_api_keys');
    }
};
