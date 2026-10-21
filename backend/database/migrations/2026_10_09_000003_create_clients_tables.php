<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Référentiel clients (§10.3, EF-CLI-01).
    public function up(): void
    {
        Schema::create('client_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('client_categories')->restrictOnDelete();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            // Changement de région en attente, effectif à la journée suivante (RG-S04).
            $table->foreignId('next_region_id')->nullable()->constrained('regions')->restrictOnDelete();
            $table->date('region_change_on')->nullable();
            $table->string('address')->nullable();
            $table->string('wilaya', 100)->nullable();
            $table->string('commune', 100)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone2', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('nif', 30)->nullable();
            $table->string('nis', 30)->nullable();
            $table->string('rc', 30)->nullable();
            $table->string('ai', 30)->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->string('status_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['region_id', 'status']);
            $table->index(['next_region_id', 'region_change_on']);
        });

        // Comptes clients rattachés au référentiel (clé étrangère prévue au lot 1).
        // Un client_id saisi en v0.1.0, avant le référentiel, ne désigne aucun
        // client : il est remis à vide (compte à rattacher depuis « Comptes »
        // après l'import des clients), sinon la clé étrangère est refusée.
        DB::table('users')
            ->whereNotNull('client_id')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('clients')->whereColumn('clients.id', 'users.client_id'))
            ->update(['client_id' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });
        Schema::dropIfExists('clients');
        Schema::dropIfExists('client_categories');
    }
};
