<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudyProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'nim' => fake()->unique()->numerify('71##########'),
            'semester' => 'I',
            'study_program_id' => StudyProgram::firstOrCreate(['name' => 'Diploma Tiga Farmasi'])->id,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Student $student) => $student->address()->create([
            'address' => 'Alamat data uji', 'city' => 'Manado',
        ]));
    }
}
