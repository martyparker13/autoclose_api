<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_sync_runs', function (Blueprint $table) {
            // Identifies how the sync was triggered:
            // null / 'api_key' = manual DMS push via API key
            // 'dealertrack'    = auto-pulled from DealerTrack Inventory API
            $table->string('source', 50)->nullable()->default(null)->after('dealer_id');
        });
    }

    public function down(): void
    {
        Schema::table('dealer_sync_runs', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
