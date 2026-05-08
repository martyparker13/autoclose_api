<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('dealer_group_id')
                ->nullable()
                ->after('dealer_id')
                ->constrained('dealer_groups')
                ->nullOnDelete();

            $table->index('dealer_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['dealer_group_id']);
            $table->dropColumn('dealer_group_id');
        });
    }
};
