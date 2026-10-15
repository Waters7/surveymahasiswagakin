<?php

namespace App\Models;

class SurveyAnswerEvidence extends Record
{
    /** "Evidence" dianggap tak berbentuk jamak oleh Laravel, sedangkan tabelnya survey_answer_evidences. */
    protected $table = 'survey_answer_evidences';

    public $timestamps = false;

    protected function casts(): array
    {
        return [];
    }
}
