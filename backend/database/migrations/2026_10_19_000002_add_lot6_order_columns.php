<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes, lot 6 (additif, compatible avec un retour à la v0.5.0) :
 * commentaire d'ajustement par ligne (§11.3, I-73), quantité expédiée
 * jamais au-dessus de la quantité validée (filet MySQL, RG-A03), index de
 * la file ADV multi-journées (statut, heure de validation, I-90).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            // Visible du client (I-73).
            $table->string('adjustment_comment', 200)->nullable()->after('adjustment_reason_code');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'decided_at']);
        });

        // La v0.5.0 n'écrit jamais shipped_qty : la contrainte ne la gêne pas.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE order_lines ADD CONSTRAINT chk_order_line_shipped_le_validated CHECK (shipped_qty IS NULL OR (validated_qty IS NOT NULL AND shipped_qty <= validated_qty))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE order_lines DROP CHECK chk_order_line_shipped_le_validated');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'decided_at']);
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('adjustment_comment');
        });
    }
};
