<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Catalogue produits et historique des prix (§10.3, EF-PRD-01, RG-P01).
    public function up(): void
    {
        Schema::create('product_families', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('product_families')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 30)->unique();
            $table->string('name');
            $table->string('short_name', 60);
            $table->foreignId('family_id')->constrained('product_families')->restrictOnDelete();
            $table->string('format', 30)->nullable();
            $table->string('sales_unit_label', 60);
            $table->unsignedSmallInteger('units_per_sales_unit')->default(1);
            $table->unsignedSmallInteger('sales_units_per_pallet')->nullable();
            $table->string('ean', 14)->nullable();
            $table->decimal('vat_rate', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('price_ht', 12, 2);
            $table->date('valid_from');
            // Veille du prix suivant ; NULL pour le dernier prix connu.
            $table->date('valid_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_families');
    }
};
