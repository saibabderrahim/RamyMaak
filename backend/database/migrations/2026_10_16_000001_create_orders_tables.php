<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes client (§10.2, lot 5) : commande et lignes, historique des
 * transitions (§6.3), numérotation par journée. Les colonnes de la
 * validation et du traitement ADV (lot 6) sont déjà présentes, vides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Seul identifiant exposé par l'API (ULID, non devinable).
            $table->char('public_id', 26)->unique();
            // Lisible, ex. RMK-261008-00042 (journée et rang dans la journée).
            // Alloué en dernier dans la transaction d'envoi : NULL le temps de
            // l'insertion seulement (plusieurs NULL admis par l'index unique).
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('business_day_id')->constrained('business_days')->restrictOnDelete();
            // Région du client au moment de l'envoi, figée (RG-S04).
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('status', 20);
            // Verrouillage optimiste : +1 à chaque écriture (RG-C05, RG-V06).
            $table->unsignedInteger('version')->default(1);
            // Idempotence de l'envoi (RG-C07).
            $table->char('client_request_id', 36);
            $table->dateTime('submitted_at');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('client_comment')->nullable();
            // Montants indicatifs (RG-P04, RG-P05).
            $table->decimal('total_ht', 14, 2)->default(0);
            $table->decimal('total_vat', 14, 2)->default(0);
            $table->decimal('total_ttc', 14, 2)->default(0);
            // Décision du régional (lot 6).
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('is_adjusted')->default(false);
            $table->string('decision_reason_code', 30)->nullable();
            $table->text('decision_comment')->nullable();
            // Traitement ADV (lot 6).
            $table->dateTime('prep_started_at')->nullable();
            $table->dateTime('prepared_at')->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->string('delivery_note_ref', 50)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'client_request_id']);
            $table->index(['business_day_id', 'region_id', 'status']);
            $table->index(['client_id', 'business_day_id']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('requested_qty');
            // Quantité validée (lot 6) et expédiée (lot 6).
            $table->unsignedInteger('validated_qty')->nullable();
            $table->unsignedInteger('shipped_qty')->nullable();
            // Prix HT et TVA figés à l'envoi (RG-P03).
            $table->decimal('unit_price_ht', 12, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->string('adjustment_reason_code', 30)->nullable();
            $table->string('shipping_gap_reason_code', 30)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'product_id']);
            $table->index('product_id');
        });

        // Historique des transitions (§6.3) : acteur et auteur (NULL = système), date, commentaire. Jamais modifié.
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            // Version de la commande après la transition.
            $table->unsignedInteger('version');
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            // Acteur au moment de la transition (OrderActor), indépendant du rôle actuel du compte.
            $table->string('actor', 10);
            $table->string('comment', 500)->nullable();
            $table->dateTime('created_at');

            $table->index(['order_id', 'id']);
        });

        // Rang des commandes dans la journée (numéro lisible), alloué dans la transaction d'envoi.
        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->foreignId('business_day_id')->primary()->constrained('business_days')->restrictOnDelete();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        // Filets de sécurité (§10.2) : MySQL applique les CHECK.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE order_lines ADD CONSTRAINT chk_order_line_requested_positive CHECK (requested_qty > 0)');
            DB::statement('ALTER TABLE order_lines ADD CONSTRAINT chk_order_line_validated_le_requested CHECK (validated_qty IS NULL OR validated_qty <= requested_qty)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
