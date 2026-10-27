<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnements Web Push des appareils (§10.3, lot 8). Un abonnement appartient
 * au dernier compte qui l'a enregistré et à sa session (I-103) : la fin de la
 * session le supprime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Session de l'appareil au dernier enregistrement (déconnexion de cet appareil).
            $table->string('session_id')->nullable()->index();
            $table->string('endpoint', 500)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('user_agent')->nullable();
            // Échecs consécutifs d'envoi (purge à 3, §9.7).
            $table->unsignedTinyInteger('failures')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // Anti-saturation (EF-NOT-02) : un état par utilisateur et par groupe de push.
        Schema::create('push_throttles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('group', 40);
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('pending')->default(0);
            // Charge utile de la dernière notification comptée : envoyée telle quelle
            // si elle est seule, son titre sert de texte au push groupé sinon.
            $table->json('last_payload')->nullable();
            // Envoi groupé programmé (fin de la fenêtre), null s'il n'y en a pas.
            $table->timestamp('flush_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'group']);
        });

        // Rappels non critiques N-10 à N-12 (EF-NOT-04).
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('reminders_enabled')->default(true)->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reminders_enabled');
        });
        Schema::dropIfExists('push_throttles');
        Schema::dropIfExists('push_subscriptions');
    }
};
