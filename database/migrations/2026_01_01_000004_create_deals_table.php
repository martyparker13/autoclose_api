<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fi_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->enum('type', [
                'warranty', 'gap', 'tire_wheel', 'paint_protection',
                'key_replacement', 'credit_life', 'credit_disability',
            ]);
            $table->string('provider', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('cost')->default(0); // in cents
            $table->unsignedBigInteger('price')->default(0); // in cents
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['dealer_id', 'is_active']);
        });

        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', [
                'draft', 'credit_submitted', 'credit_approved', 'credit_declined',
                'docs_pending', 'docs_signed', 'awaiting_delivery', 'delivered', 'cancelled',
            ])->default('draft');
            $table->unsignedBigInteger('sale_price')->default(0);
            $table->unsignedBigInteger('down_payment')->default(0);
            $table->unsignedBigInteger('trade_in_value')->default(0);
            $table->json('trade_in_vehicle')->nullable();
            $table->unsignedBigInteger('finance_amount')->nullable();
            $table->decimal('apr', 5, 3)->nullable();
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->unsignedBigInteger('monthly_payment')->nullable();
            $table->string('lender', 200)->nullable();
            $table->json('fi_products')->nullable();
            $table->unsignedBigInteger('total_fi_income')->default(0);
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['web', 'mobile', 'in_store'])->default('web');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['dealer_id', 'status']);
            $table->index('buyer_id');
            $table->index('vehicle_id');
        });

        Schema::create('deal_fi_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fi_product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('cost')->default(0);
            $table->timestamps();

            $table->index('deal_id');
        });

        Schema::create('deal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'buyers_order', 'retail_installment', 'title_app', 'odometer',
                'we_owe', 'insurance_proof', 'id_verification', 'income_verification',
            ]);
            $table->string('docusign_envelope_id')->nullable();
            $table->string('docusign_status', 50)->nullable();
            $table->string('s3_path')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->index('deal_id');
            $table->index('docusign_envelope_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_documents');
        Schema::dropIfExists('deal_fi_products');
        Schema::dropIfExists('deals');
        Schema::dropIfExists('fi_products');
    }
};
