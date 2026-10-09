<?php

namespace App\Observers;

use App\Models\Student;
use App\Services\Audit;

class StudentObserver
{
    public function updated(Student $student): void
    {
        $fields = ['name', 'nim', 'registration_number', 'study_program_id', 'semester', 'phone'];
        $after = array_intersect_key($student->getChanges(), array_flip($fields));
        if ($after) {
            Audit::record('student.identity_changed', $student,
                array_intersect_key($student->getOriginal(), $after), $after);
        }
    }
}
