<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prolongation de l'heure limite de validation par région (RG-A05, RG-V08,
 * I-71) : même règle que la répartition et la commande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('region_days', function (Blueprint $table) {
            $table->dateTime('validation_cutoff_override_at')->nullable()->after('ordering_cutoff_override_at');
        });
    }

    public function down(): void
    {
        Schema::table('region_days', function (Blueprint $table) {
            $table->dropColumn('validation_cutoff_override_at');
        });
    }
};
