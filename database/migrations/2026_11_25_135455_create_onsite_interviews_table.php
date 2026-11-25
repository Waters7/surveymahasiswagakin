<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daftar mahasiswa wawancara onsite yang dapat diambil petugas. Penugasan semula disimpan agar dapat
     * dipulihkan bila petugas melepas mahasiswa sebelum wawancara dimulai.
     */
    public function up(): void
    {
        Schema::create('onsite_interviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('survey_period_id')->constrained();
            $table->text('notes')->nullable();
            $table->foreignId('survey_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('claimed_by')->nullable()->constrained('users');
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('previous_user_id')->nullable()->constrained('users');
            $table->string('previous_method', 20)->nullable();
            $table->date('previous_scheduled_at')->nullable();
            $table->date('previous_scheduled_until')->nullable();
            $table->string('previous_status')->nullable();
            $table->string('previous_reference')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'survey_period_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onsite_interviews');
    }
};
