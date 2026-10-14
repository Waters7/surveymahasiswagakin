<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_assignments', function (Blueprint $table): void {
            $table->string('method', 20)->default('lokasi')->index();
        });
        Schema::table('surveys', function (Blueprint $table): void {
            $table->string('method', 20)->default('lokasi');
        });
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->string('survey_method', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('survey_assignments', function (Blueprint $table): void {
            $table->dropIndex(['method']);
            $table->dropColumn('method');
        });
        Schema::table('surveys', function (Blueprint $table): void {
            $table->dropColumn('method');
        });
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn('survey_method');
        });
    }
};
