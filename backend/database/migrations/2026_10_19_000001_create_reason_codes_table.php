<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motifs (§10.3, EF-ADM-05, lot 6) : rejet, ajustement, écart d'expédition
 * et annulation. Le code est celui des colonnes *_reason_code des commandes,
 * sans clé étrangère (le type est donné par la colonne) ; il ne change
 * jamais et un motif se désactive, sans être supprimé (I-82).
 *
 * Les motifs par défaut sont insérés ici, une seule fois par environnement :
 * le déploiement ne sème que les rôles, et une modification de l'Admin n'est
 * jamais écrasée.
 */
return new class extends Migration
{
    /** @var array<string, list<array{0: string, 1: string, 2?: bool}>> type → [code, libellé, commentaire exigé] */
    private const DEFAULTS = [
        'ADJUSTMENT' => [
            ['QUOTA_INSUFFISANT', 'Quota insuffisant'],
            ['BORNE_ATTEINTE', 'Borne du client atteinte'],
            ['DEMANDE_CLIENT', 'À la demande du client'],
            ['AUTRE', 'Autre', true],
        ],
        'REJECTION' => [
            ['QUOTA_EPUISE', 'Quota épuisé'],
            ['CLIENT_BLOQUE', 'Client bloqué'],
            ['DOUBLON', 'Commande en double'],
            ['DEMANDE_CLIENT', 'À la demande du client'],
            ['AUTRE', 'Autre', true],
        ],
        'SHIPPING_GAP' => [
            ['RUPTURE', 'Rupture de stock'],
            ['CASSE', 'Casse'],
            ['ERREUR_PREPARATION', 'Erreur de préparation'],
            // Écart d'expédition : pas de commentaire stocké sur la ligne (I-95).
            ['AUTRE', 'Autre'],
        ],
        'CANCELLATION' => [
            ['DEMANDE_CLIENT', 'À la demande du client'],
            ['RUPTURE', 'Rupture de stock'],
            ['ERREUR_SAISIE', 'Erreur de saisie'],
            ['AUTRE', 'Autre', true],
        ],
    ];

    public function up(): void
    {
        Schema::create('reason_codes', function (Blueprint $table) {
            $table->id();
            // ReasonType : REJECTION, ADJUSTMENT, SHIPPING_GAP, CANCELLATION.
            $table->string('type', 20);
            // ^[A-Z][A-Z0-9_]{1,29}$, immuable ; même longueur que les colonnes des commandes.
            $table->string('code', 30);
            $table->string('label', 100);
            $table->boolean('requires_comment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['type', 'code']);
            $table->unique(['type', 'label']);
            $table->index(['type', 'is_active', 'sort_order']);
        });

        $now = now();
        $rows = [];
        foreach (self::DEFAULTS as $type => $codes) {
            foreach ($codes as $index => $code) {
                $rows[] = [
                    'type' => $type,
                    'code' => $code[0],
                    'label' => $code[1],
                    'requires_comment' => $code[2] ?? false,
                    'is_active' => true,
                    'sort_order' => ($index + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('reason_codes')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('reason_codes');
    }
};
