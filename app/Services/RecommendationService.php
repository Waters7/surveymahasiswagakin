<?php

namespace App\Services;

class RecommendationService
{
    public function suggest(array $answers, bool $pkh, array $rule, array $criteria = []): string
    {
        if (! ($rule['auto_suggest'] ?? false)) {
            return 'perlu_verifikasi';
        }
        if ($pkh && ($rule['pkh_recommended'] ?? false)) {
            return 'direkomendasikan';
        }
        if (in_array('perlu_verifikasi', array_column($answers, 'answer'), true)) {
            return 'perlu_verifikasi';
        }
        $yes = count(array_filter($answers, fn ($a) => ($a['answer'] ?? null) === 'ya'));
        if (($rule['minimum_criteria'] ?? null) === null) {
            return 'perlu_verifikasi';
        }
        $weight = collect($criteria)->filter(fn ($c) => collect($answers)->contains(fn ($a) => ($a['criterion_id'] ?? null) == $c['id'] && ($a['answer'] ?? null) === 'ya'))->sum('weight');

        return $yes >= $rule['minimum_criteria'] && (($rule['minimum_weight'] ?? null) === null || $weight >= $rule['minimum_weight']) ? 'direkomendasikan' : 'tidak_direkomendasikan';
    }
}
