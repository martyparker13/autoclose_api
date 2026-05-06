<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->timestamp('deposit_paid_at')->nullable()->after('notes');
            $table->string('deposit_payment_id', 255)->nullable()->after('deposit_paid_at');
            $table->unsignedBigInteger('deposit_amount')->default(0)->after('deposit_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['deposit_paid_at', 'deposit_payment_id', 'deposit_amount']);
        });
    }
};
