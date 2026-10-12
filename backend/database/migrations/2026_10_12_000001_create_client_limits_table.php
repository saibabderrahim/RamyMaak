<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bornes client (§5.3, §10.2) : plafond B(c,p) publié et brouillon en
 * attente d'envoi (RG-B07), pour une région et une journée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_day_id')->constrained('region_days')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // Borne publiée B(c,p) ; NULL = pas de borne (RG-B01).
            $table->unsignedInteger('max_qty')->nullable();
            // Brouillon : has_draft distingue « supprimer la borne » (draft NULL) de « rien à envoyer ».
            $table->unsignedInteger('draft_max_qty')->nullable();
            $table->boolean('has_draft')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['region_day_id', 'client_id', 'product_id']);
            $table->index(['region_day_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_limits');
    }
};
