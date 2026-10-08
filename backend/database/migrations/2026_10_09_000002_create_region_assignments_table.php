<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Affectations aux régions : titulaire, suppléants avec période, ADV
    // (§3.3, §3.4, RG-S05). Une fin d'affectation est une révocation datée :
    // l'historique est conservé (RG-S03).
    public function up(): void
    {
        Schema::create('region_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 10);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'kind']);
            $table->index(['region_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_assignments');
    }
};
