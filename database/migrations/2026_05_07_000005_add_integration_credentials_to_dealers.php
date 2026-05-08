<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            // Encrypted JSON — stores DealerTrack (Cox Automotive) OAuth credentials
            $table->text('dealertrack_credentials')->nullable()->after('dms_credentials');
            // Encrypted JSON — stores RouteOne technology partner + dealer credentials
            $table->text('routeone_credentials')->nullable()->after('dealertrack_credentials');
        });
    }

    public function down(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            $table->dropColumn(['dealertrack_credentials', 'routeone_credentials']);
        });
    }
};
