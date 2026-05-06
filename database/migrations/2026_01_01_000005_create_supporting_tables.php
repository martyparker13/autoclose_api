<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->text('ssn_encrypted')->nullable();
            $table->date('dob')->nullable();
            $table->unsignedBigInteger('annual_income')->nullable();
            $table->string('employment_status', 50)->nullable();
            $table->string('employer_name', 200)->nullable();
            $table->string('employer_phone', 20)->nullable();
            $table->unsignedBigInteger('monthly_housing')->nullable();
            $table->string('housing_status', 50)->nullable();
            $table->tinyInteger('years_at_employer')->nullable();
            $table->string('credit_score_range', 50)->nullable();
            $table->enum('bureau_pull_type', ['soft', 'hard'])->default('soft');
            $table->text('bureau_response')->nullable(); // encrypted JSON
            $table->enum('decision', ['pending', 'approved', 'declined', 'conditional'])->default('pending');
            $table->unsignedBigInteger('approved_amount')->nullable();
            $table->decimal('approved_apr', 5, 3)->nullable();
            $table->unsignedSmallInteger('approved_term')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['deal_id']);
            $table->index('buyer_id');
        });

        Schema::create('trade_in_appraisals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('year')->unsigned()->nullable();
            $table->string('make', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('trim', 100)->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('vin', 17)->nullable();
            $table->enum('condition', ['excellent', 'good', 'fair', 'poor'])->default('good');
            $table->unsignedBigInteger('kbb_value')->nullable();
            $table->unsignedBigInteger('black_book_value')->nullable();
            $table->unsignedBigInteger('dealer_offer')->nullable();
            $table->boolean('accepted')->default(false);
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index('dealer_id');
            $table->index('deal_id');
        });

        Schema::create('delivery_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['home_delivery', 'lot_pickup'])->default('lot_pickup');
            $table->timestamp('scheduled_at');
            $table->json('address')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['scheduled', 'en_route', 'completed', 'cancelled'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('deal_id');
            $table->index(['dealer_id', 'status']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 100);
            $table->string('model_type', 200)->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['dealer_id', 'created_at']);
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('delivery_appointments');
        Schema::dropIfExists('trade_in_appraisals');
        Schema::dropIfExists('credit_applications');
    }
};
