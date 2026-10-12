<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_addresses', function (Blueprint $table): void {
            $table->decimal('approx_latitude', 10, 7)->nullable();
            $table->decimal('approx_longitude', 10, 7)->nullable();
            $table->string('approx_precision', 20)->nullable();
            $table->string('approx_label')->nullable();
            $table->timestamp('geocoded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_addresses', function (Blueprint $table): void {
            $table->dropColumn(['approx_latitude', 'approx_longitude', 'approx_precision', 'approx_label', 'geocoded_at']);
        });
    }
};
