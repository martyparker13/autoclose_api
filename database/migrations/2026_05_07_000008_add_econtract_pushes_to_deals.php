<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            // Each entry: {platform, external_id, status, pushed_at, signed_at, error}
            // status: pending | signed | voided | error
            $table->json('econtract_pushes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('econtract_pushes');
        });
    }
};
