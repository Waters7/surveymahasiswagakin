<?php

namespace App\Services;

use App\Models\SurveyAssignment;

class AssignmentQuery
{
    public static function filtered($user, array $filters = [])
    {
        $q = SurveyAssignment::with(['student.program', 'student.address', 'student.parents', 'user', 'survey', 'period', 'latestFailure']);
        if (! $user->can('surveys.view-all')) {
            $q->where('user_id', $user->id);
        }
        if ($v = $filters['q'] ?? null) {
            $q->whereHas('student', fn ($s) => $s->where(fn ($s) => $s->where('name', 'like', "%$v%")->orWhere('nim', 'like', "%$v%")->orWhere('phone', 'like', "%$v%")->orWhereHas('address', fn ($a) => $a->where('address', 'like', "%$v%"))));
        }
        foreach (['status', 'user_id', 'survey_period_id', 'survey_team_id', 'method'] as $f) {
            if ($v = $filters[$f] ?? null) {
                $q->where($f, $v);
            }
        }
        if ($v = $filters['date'] ?? null) {
            $q->whereDate('scheduled_at', '<=', $v)->where(fn ($range) => $range->whereDate('scheduled_until', '>=', $v)->orWhere(fn ($single) => $single->whereNull('scheduled_until')->whereDate('scheduled_at', $v)));
        }
        if ($v = $filters['program'] ?? null) {
            $q->whereHas('student', fn ($s) => $s->where('study_program_id', $v));
        }
        if ($v = $filters['city'] ?? null) {
            $q->whereHas('student.address', fn ($s) => $s->where('city', $v));
        }
        if ($v = $filters['semester'] ?? null) {
            $q->whereHas('student', fn ($s) => $s->where('semester', $v));
        }
        if ($v = $filters['recommendation'] ?? null) {
            $q->whereHas('survey', fn ($s) => $s->where('recommendation', $v));
        }
        if (isset($filters['pkh']) && $filters['pkh'] !== '') {
            $q->whereHas('survey', fn ($s) => $s->where('pkh', (bool) $filters['pkh']));
        }

        return $q;
    }
}
