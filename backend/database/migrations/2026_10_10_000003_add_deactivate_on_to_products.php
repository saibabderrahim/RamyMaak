<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // RG-Q07 : un produit doté d'un quota publié sur la journée en cours n'est
    // désactivé qu'à partir de la journée suivante.
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->date('deactivate_on')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('deactivate_on');
        });
    }
};
