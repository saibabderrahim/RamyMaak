<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jetons « Rester connecté » par appareil (EF-AUTH-06, contrat du lot 8 §5,
 * I-113) : un par connexion avec « Rester connecté », enregistré haché. La
 * déconnexion d'un appareil supprime le sien ; les autres appareils restent
 * connectés. `users.remember_token` est gardé, mais n'ouvre plus de session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remember_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // SHA-256 du jeton du cookie : le jeton lui-même n'est jamais enregistré.
            $table->char('token_hash', 64)->unique();
            // Dernière session ouverte par ce jeton (connexion, puis retour après expiration).
            $table->string('session_id')->nullable()->index();
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remember_tokens');
    }
};
