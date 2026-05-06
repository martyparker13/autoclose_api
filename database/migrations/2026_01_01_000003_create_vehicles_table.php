<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->string('vin', 17)->nullable();
            $table->string('stock_number', 50)->nullable();
            $table->smallInteger('year')->unsigned();
            $table->string('make', 100);
            $table->string('model', 100);
            $table->string('trim', 100)->nullable();
            $table->string('body_style', 50)->nullable();
            $table->string('exterior_color', 100)->nullable();
            $table->string('interior_color', 100)->nullable();
            $table->unsignedInteger('mileage')->default(0);
            $table->enum('condition', ['new', 'used', 'certified'])->default('used');
            $table->unsignedBigInteger('price')->default(0); // in cents
            $table->unsignedBigInteger('msrp')->nullable();
            $table->unsignedBigInteger('internet_price')->nullable();
            $table->unsignedBigInteger('cost')->nullable(); // hidden from API
            $table->string('transmission', 50)->nullable();
            $table->string('engine', 100)->nullable();
            $table->string('drivetrain', 20)->nullable();
            $table->string('fuel_type', 50)->nullable();
            $table->tinyInteger('doors')->nullable();
            $table->tinyInteger('cylinders')->nullable();
            $table->enum('status', ['available', 'pending', 'sold', 'hold'])->default('available');
            $table->text('description')->nullable();
            $table->string('carfax_url')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('dealer_id');
            $table->index(['dealer_id', 'status']);
            $table->index('vin');
            $table->index('stock_number');
        });

        Schema::create('vehicle_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['photo', 'video', 'document'])->default('photo');
            $table->string('url');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'sort_order']);
        });

        Schema::create('vehicle_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('category', 100)->nullable();
            $table->string('feature_name', 200);
            $table->timestamps();

            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_features');
        Schema::dropIfExists('vehicle_media');
        Schema::dropIfExists('vehicles');
    }
};
