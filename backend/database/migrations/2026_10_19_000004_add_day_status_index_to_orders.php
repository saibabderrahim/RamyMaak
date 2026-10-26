<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lecture verrouillante de la clôture (commandes encore envoyées d'une
 * journée, par identifiant) : un index (journée, statut, id) la garde sur sa
 * journée. Par l'index (statut, validée le) du lot 6, elle verrouillerait les
 * commandes envoyées des autres journées ouvertes et pourrait s'interbloquer
 * avec une décision sur la journée du lendemain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['business_day_id', 'status', 'id'], 'orders_day_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_day_status_index');
        });
    }
};
