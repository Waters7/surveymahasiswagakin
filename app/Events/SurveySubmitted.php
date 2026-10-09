<?php

namespace App\Events;

use App\Models\Survey;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SurveySubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable,SerializesModels;

    public function __construct(public Survey $survey) {}
}
