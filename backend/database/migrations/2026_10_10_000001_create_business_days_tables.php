<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Journées commerciales, journées régionales et quotas (§10.2, §6.1).
    public function up(): void
    {
        Schema::create('business_days', function (Blueprint $table) {
            $table->id();
            $table->date('day')->unique();
            $table->date('delivery_date')->nullable();
            // Heures limites de la journée, en UTC (§4.3) ; copiées des paramètres à l'ouverture.
            $table->dateTime('distribution_cutoff_at');
            $table->dateTime('ordering_cutoff_at');
            $table->dateTime('validation_cutoff_at');
            $table->dateTime('closing_at');
            $table->string('status', 10)->default('OPEN');
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'closing_at']);
        });

        Schema::create('region_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_day_id')->constrained('business_days')->restrictOnDelete();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->string('status', 30)->default('QUOTA_DRAFT');
            $table->unsignedInteger('quota_version')->default(0);
            $table->dateTime('quota_published_at')->nullable();
            $table->foreignId('quota_published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('quota_last_published_at')->nullable();
            $table->unsignedInteger('distribution_version')->default(0);
            $table->dateTime('distribution_published_at')->nullable();
            $table->foreignId('distribution_published_by')->nullable()->constrained('users')->restrictOnDelete();
            // Prolongations accordées par l'ADV pour cette région (RG-A05).
            $table->dateTime('distribution_cutoff_override_at')->nullable();
            $table->dateTime('ordering_cutoff_override_at')->nullable();
            $table->timestamps();

            $table->unique(['business_day_id', 'region_id']);
        });

        Schema::create('region_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_day_id')->constrained('region_days')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // Quota publié Q(r,p) ; brouillon en attente de publication (RG-Q03).
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('draft_quantity')->nullable();
            // Compteur V(r,p), modifié uniquement par la validation des commandes (§5.8).
            $table->unsignedInteger('validated_qty')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['region_day_id', 'product_id']);
            $table->index('product_id');
        });

        // Filet de sécurité de l'invariant I-1 (§5.8) : MySQL applique les CHECK.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE region_quotas ADD CONSTRAINT chk_region_quota_not_exceeded CHECK (validated_qty <= quantity)');
        }

        // Journée concernée par un import de quotas (prévue au lot 2).
        Schema::table('imports', function (Blueprint $table) {
            $table->foreign('business_day_id')->references('id')->on('business_days')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropForeign(['business_day_id']);
        });
        Schema::dropIfExists('region_quotas');
        Schema::dropIfExists('region_days');
        Schema::dropIfExists('business_days');
    }
};
