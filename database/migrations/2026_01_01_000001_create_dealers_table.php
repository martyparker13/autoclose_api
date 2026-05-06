<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subdomain')->unique();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 7)->default('#01696f');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('license_number', 50)->nullable();
            $table->enum('dms_provider', ['cdk', 'dealersocket', 'vinsolutions', 'manual'])->default('manual');
            $table->text('dms_credentials')->nullable(); // encrypted JSON
            $table->enum('subscription_plan', ['starter', 'professional', 'enterprise'])->default('starter');
            $table->string('subscription_status', 50)->default('trialing');
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('feature_flags')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('subdomain');
            $table->index('custom_domain');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealers');
    }
};
