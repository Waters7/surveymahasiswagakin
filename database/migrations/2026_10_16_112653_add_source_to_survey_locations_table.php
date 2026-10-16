<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Titik lokasi dapat berasal dari GPS perangkat atau dipilih surveyor di peta; titik peta tidak memiliki akurasi.
     */
    public function up(): void
    {
        Schema::table('survey_locations', function (Blueprint $table) {
            $table->string('source', 10)->default('gps')->after('accuracy');
            $table->decimal('accuracy', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_locations', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
