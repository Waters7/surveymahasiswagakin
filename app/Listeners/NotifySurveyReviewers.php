<?php

namespace App\Listeners;

use App\Events\SurveySubmitted;
use App\Models\User;
use App\Notifications\SurveyNotice;

class NotifySurveyReviewers
{
    public function handle(SurveySubmitted $event): void
    {
        foreach (User::permission('surveys.verify')->where('active', true)->get() as $u) {
            $u->notify(new SurveyNotice('Survey '.$event->survey->student->name.' menunggu verifikasi.', '/surveys/'.$event->survey->uuid));
        }
    }
}
