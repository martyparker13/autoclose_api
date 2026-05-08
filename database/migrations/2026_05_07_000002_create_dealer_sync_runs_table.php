<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default('queued'); // queued|running|completed|failed
            $table->boolean('archive_missing')->default(false);

            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedSmallInteger('chunk_size')->default(0);
            $table->unsignedSmallInteger('total_jobs')->default(0);
            $table->unsignedSmallInteger('processed_jobs')->default(0);

            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('archived')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['dealer_id', 'created_at']);
            $table->index(['dealer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_sync_runs');
    }
};
