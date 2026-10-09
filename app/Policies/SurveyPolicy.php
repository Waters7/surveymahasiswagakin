<?php

namespace App\Policies;

use App\Models\Survey;
use App\Models\User;

class SurveyPolicy
{
    public function view(User $u, Survey $s): bool
    {
        return $u->active && ($u->can('surveys.view-all') || ($u->can('surveys.work') && $s->user_id === $u->id));
    }

    public function update(User $u, Survey $s): bool
    {
        return $u->active && $u->can('surveys.work') && $s->user_id === $u->id && $s->editable();
    }

    public function verify(User $u, Survey $s): bool
    {
        return $u->active && $u->can('surveys.verify') && $u->id !== $s->user_id && $s->status === 'menunggu_verifikasi';
    }
}
