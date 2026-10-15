<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat kunjungan yang tidak dapat dilaksanakan. Penugasan tetap dapat dicoba lagi.
     */
    public function up(): void
    {
        Schema::create('survey_failures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('survey_assignment_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('reason', 30);
            $table->string('notes', 200)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['survey_assignment_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_failures');
    }
};
