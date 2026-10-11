<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_assignments', function (Blueprint $table): void {
            $table->date('scheduled_until')->nullable();
            $table->string('assignment_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('survey_assignments', function (Blueprint $table): void {
            $table->dropColumn(['scheduled_until', 'assignment_reference']);
        });
    }
};
