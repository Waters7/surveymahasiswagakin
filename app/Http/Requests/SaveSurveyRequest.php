<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('survey'));
    }

    public function rules(): array
    {
        return ['revision' => 'required|integer|min:0', 'answers' => 'sometimes|array|max:100', 'answers.*.criterion_id' => 'required|integer|distinct', 'answers.*.answer' => 'nullable|in:ya,tidak,perlu_verifikasi', 'answers.*.detail' => 'nullable|string|max:4000', 'answers.*.notes' => 'nullable|string|max:4000', 'pkh' => 'nullable|boolean', 'recommendation' => 'nullable|in:direkomendasikan,tidak_direkomendasikan,perlu_verifikasi', 'conclusion' => 'nullable|string|max:10000', 'group_photo_reason' => 'nullable|string|max:2000', 'residence' => 'nullable|array', 'residence.ownership' => 'nullable|string|max:200', 'residence.household_members' => 'nullable|integer|min:1|max:100', 'residence.income' => 'nullable|numeric|min:0|max:100000000000', 'location' => 'nullable|array', 'location.latitude' => 'required_with:location|numeric|between:-90,90', 'location.longitude' => 'required_with:location|numeric|between:-180,180', 'location.accuracy' => 'exclude_if:location.source,peta|required_with:location|numeric|min:0|max:100000', 'location.source' => 'nullable|in:gps,peta', 'student' => 'sometimes|array', 'student.parents' => 'sometimes|array|max:3', 'student.parents.*.relation' => 'required|in:ayah,ibu,wali', 'student.parents.*.name' => 'nullable|string|max:255', 'student.parents.*.occupation' => 'nullable|string|max:255', 'student.parents.*.education' => 'nullable|string|max:255', 'student.parents.*.phone' => 'nullable|string|max:30'];
    }
}
