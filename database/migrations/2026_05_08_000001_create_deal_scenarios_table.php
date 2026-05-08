<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->enum('label', ['good', 'better', 'best']);
            $table->unsignedInteger('term_months');
            $table->unsignedInteger('down_payment');       // cents
            $table->unsignedInteger('sale_price');          // cents
            $table->json('fi_product_ids');                 // array of FI product IDs included
            $table->decimal('apr', 5, 3);                   // e.g. 6.900
            $table->unsignedInteger('monthly_payment');     // cents
            $table->unsignedInteger('total_cost');          // cents (monthly_payment * term + down)
            $table->boolean('is_selected')->default(false);
            $table->timestamps();

            $table->unique(['deal_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_scenarios');
    }
};
