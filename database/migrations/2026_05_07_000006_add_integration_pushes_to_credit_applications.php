<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->json('integration_pushes')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('credit_applications', function (Blueprint $table) {
            $table->dropColumn('integration_pushes');
        });
    }
};
