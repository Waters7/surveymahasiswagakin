<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
            $t->string('nip', 32)->nullable()->unique();
            $t->string('phone')->nullable();
            $t->string('position')->nullable();
            $t->string('unit')->nullable();
            $t->boolean('active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->softDeletes();
        });
        Schema::create('study_programs', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('survey_form_versions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('version');
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
        Schema::create('survey_criteria', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_form_version_id')->constrained();
            $t->unsignedInteger('position');
            $t->text('label');
            $t->string('evidence_mode')->default('optional');
            $t->decimal('weight', 8, 2)->default(1);
            $t->timestamps();
        });
        Schema::create('survey_periods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('academic_year');
            $t->foreignId('survey_form_version_id')->constrained();
            $t->date('starts_at');
            $t->date('ends_at');
            $t->timestamps();
        });
        Schema::create('recommendation_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_period_id')->unique()->constrained();
            $t->unsignedInteger('minimum_criteria')->nullable();
            $t->decimal('minimum_weight', 8, 2)->nullable();
            $t->boolean('pkh_recommended')->default(true);
            $t->boolean('auto_suggest')->default(true);
            $t->timestamps();
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('registration_number')->nullable()->unique();
            $t->string('nim', 32)->nullable()->unique();
            $t->string('name');
            $t->string('semester')->nullable();
            $t->foreignId('study_program_id')->constrained();
            $t->string('phone')->nullable();
            $t->string('type')->default('mahasiswa');
            $t->string('admission_period')->nullable();
            $t->string('academic_year')->nullable();
            $t->string('source_team')->nullable();
            $t->string('source_schedule')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('student_parents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained();
            $t->string('relation');
            $t->string('name')->nullable();
            $t->string('occupation')->nullable();
            $t->string('education')->nullable();
            $t->string('phone')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'relation']);
        });
        Schema::create('student_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->unique()->constrained();
            $t->text('address');
            foreach (['hamlet', 'village', 'district', 'city', 'province', 'postal_code'] as $c) {
                $t->string($c)->nullable();
            }
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->timestamps();
        });
        Schema::create('survey_teams', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('surveyors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('survey_team_id')->constrained();
            $t->timestamps();
            $t->unique(['user_id', 'survey_team_id']);
        });
        Schema::create('user_integrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->string('provider')->default('pangi');
            $t->uuid('external_user_id')->unique();
            $t->string('nip', 32)->nullable();
            $t->string('email')->nullable();
            $t->string('status')->default('connected');
            $t->timestamp('connected_at');
            $t->timestamps();
        });
        Schema::create('travel_links', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained();
            $t->uuid('pangi_trip_id');
            $t->string('spd_number');
            $t->date('starts_at');
            $t->date('ends_at');
            $t->json('trip_data');
            $t->json('draft')->nullable();
            $t->unsignedInteger('draft_version')->default(0);
            $t->string('status')->default('connected');
            $t->string('idempotency_key')->nullable();
            $t->string('draft_hash')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'pangi_trip_id']);
        });
        Schema::create('survey_assignments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('student_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('survey_period_id')->constrained();
            $t->foreignId('survey_team_id')->nullable()->constrained();
            $t->foreignId('travel_link_id')->nullable()->constrained();
            $t->date('scheduled_at')->nullable();
            $t->string('status')->default('belum_dijadwalkan');
            $t->timestamps();
            $t->unique(['student_id', 'survey_period_id']);
        });
        Schema::create('surveys', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('survey_assignment_id')->unique()->constrained();
            $t->foreignId('student_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('survey_form_version_id')->constrained();
            $t->foreignId('travel_link_id')->nullable()->constrained();
            $t->json('criteria_snapshot');
            $t->json('rules_snapshot');
            $t->json('settings_snapshot');
            $t->json('student_snapshot');
            $t->json('residence')->nullable();
            $t->boolean('pkh')->nullable();
            $t->string('recommendation')->nullable();
            $t->text('conclusion')->nullable();
            $t->text('group_photo_reason')->nullable();
            $t->string('status')->default('draft');
            $t->unsignedInteger('revision')->default(0);
            $t->timestamp('started_at');
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->string('document_hash', 64)->nullable();
            $t->string('verify_token', 64)->nullable()->unique();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('survey_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained();
            $t->unsignedBigInteger('criterion_id');
            $t->string('answer')->nullable();
            $t->text('detail')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['survey_id', 'criterion_id']);
        });
        Schema::create('survey_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->unique()->constrained();
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->decimal('accuracy', 10, 2);
            $t->timestamp('captured_at');
            $t->timestamps();
        });
        Schema::create('survey_photos', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('survey_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unsignedBigInteger('criterion_id')->nullable();
            $t->string('kind')->default('evidence');
            $t->string('path');
            $t->string('sha256', 64);
            $t->json('metadata');
            $t->timestamps();
        });
        Schema::create('survey_answer_evidences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_answer_id')->constrained();
            $t->foreignId('survey_photo_id')->unique()->constrained()->cascadeOnDelete();
        });
        Schema::create('survey_signatures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained();
            $t->string('role');
            $t->string('signed_by');
            $t->string('relation')->nullable();
            $t->string('path');
            $t->string('document_hash', 64);
            $t->timestamp('signed_at');
            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamps();
            $t->unique(['survey_id', 'role']);
        });
        Schema::create('survey_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('decision');
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('survey_status_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('from_status')->nullable();
            $t->string('to_status');
            $t->text('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('travel_visit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained();
            $t->string('event');
            $t->timestamp('occurred_at');
            $t->timestamps();
        });
        Schema::create('integration_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('provider')->default('pangi');
            $t->string('direction')->default('outbound');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('travel_link_id')->constrained();
            $t->string('action');
            $t->string('external_reference');
            $t->string('idempotency_key');
            $t->string('status');
            $t->unsignedInteger('http_status')->nullable();
            $t->string('error_message')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('subject_type');
            $t->string('subject_id');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamps();
        });
        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value');
            $t->timestamps();
        });
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained();
            $t->string('type');
            $t->string('filename');
            $t->json('rows');
            $t->json('errors');
            $t->json('mapping');
            $t->string('status')->default('preview');
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (array_reverse(['study_programs', 'survey_form_versions', 'survey_criteria', 'survey_periods', 'recommendation_rules', 'students', 'student_parents', 'student_addresses', 'survey_teams', 'surveyors', 'user_integrations', 'travel_links', 'survey_assignments', 'surveys', 'survey_answers', 'survey_locations', 'survey_photos', 'survey_answer_evidences', 'survey_signatures', 'survey_verifications', 'survey_status_histories', 'travel_visit_logs', 'integration_logs', 'audit_logs', 'system_settings', 'import_batches', 'notifications']) as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['uuid', 'nip', 'phone', 'position', 'unit', 'active', 'must_change_password', 'deleted_at']));
    }
};
