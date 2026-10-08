<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Historique des imports Excel (§8, §10.3 « quota_imports ») : un import
    // par fichier déposé, avec son rapport d'analyse et son application.
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            // Journée concernée (quotas, bornes) : clé étrangère ajoutée au lot 3.
            $table->unsignedBigInteger('business_day_id')->nullable()->index();
            $table->string('mode', 10)->nullable();
            $table->string('status', 12)->default('PENDING');
            $table->string('original_name');
            $table->string('file_path');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_processed')->default(0);
            $table->unsignedInteger('rows_ok')->default(0);
            $table->unsignedInteger('rows_error')->default(0);
            $table->json('errors')->nullable();
            $table->json('summary')->nullable();
            $table->json('preview')->nullable();
            $table->string('error_report_path')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
