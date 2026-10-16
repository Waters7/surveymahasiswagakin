<?php

namespace App\Services;

use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveyAssignment;
use App\Models\SurveyFailure;
use App\Models\SurveyVerification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Angka dan temuan dashboard hasil survey, dihitung dari penugasan yang boleh dilihat pengguna.
 */
class SurveyResultInsights
{
    /**
     * @param  Collection<int, SurveyAssignment>  $assignments
     * @return array{kpis: array{total: int, submitted: int, verified: int, recommended: int, waiting: int}, composition: Collection<string, int>, byProgram: Collection<string, Collection<string, int>>, byCity: Collection<string, Collection<string, int>>, criteria: Collection<int, array{label: string, fulfilled: int, answered: int, percent: int}>, insights: list<array{icon: string, text: string}>}
     */
    public function summarize(Collection $assignments): array
    {
        $submitted = $assignments->pluck('survey')->filter(fn (?Survey $survey): bool => $survey?->submitted_at !== null)->values();
        $verified = $submitted->filter(fn (Survey $survey): bool => in_array($survey->status, ['disetujui', 'tidak_direkomendasikan'], true));
        $composition = $this->countByResult($assignments);
        $kpis = ['total' => $assignments->count(), 'submitted' => $submitted->count(), 'verified' => $verified->count(), 'recommended' => $composition['direkomendasikan'], 'waiting' => $composition['dalam_verifikasi']];
        $byProgram = $this->breakdown($assignments, fn (SurveyAssignment $a): string => $a->student->program->name);
        $byCity = $this->breakdown($assignments, fn (SurveyAssignment $a): string => $a->student->address?->city ?: 'Belum diisi');
        $criteria = $this->criteria($submitted);

        return compact('kpis', 'composition', 'byProgram', 'byCity', 'criteria') + ['insights' => $this->insights($assignments, $kpis, $byProgram, $byCity, $criteria, $verified)];
    }

    /**
     * @param  Collection<int, SurveyAssignment>  $rows
     * @return Collection<string, int>
     */
    public function countByResult(Collection $rows): Collection
    {
        return collect(SurveyAssignment::RESULT_LABELS)->map(fn (string $label, string $key): int => $rows->filter(fn (SurveyAssignment $a): bool => $a->resultCategory() === $key)->count());
    }

    /**
     * @param  Collection<int, SurveyAssignment>  $assignments
     * @return Collection<string, Collection<string, int>>
     */
    private function breakdown(Collection $assignments, callable $group): Collection
    {
        return $assignments->groupBy($group)->map(fn (Collection $rows): Collection => $this->countByResult($rows)->put('total', $rows->count()))->sortKeys();
    }

    /**
     * Persentase jawaban "ya" per kriteria pada survey yang sudah dikirim, dari yang paling sering terpenuhi.
     *
     * @param  Collection<int, Survey>  $submitted
     * @return Collection<int, array{label: string, fulfilled: int, answered: int, percent: int}>
     */
    private function criteria(Collection $submitted): Collection
    {
        $labels = [];
        foreach ($submitted as $survey) {
            foreach ($survey->criteria_snapshot ?? [] as $criterion) {
                $labels[$criterion['id']] ??= $criterion['label'];
            }
        }

        return SurveyAnswer::whereIn('survey_id', $submitted->pluck('id'))->whereNotNull('answer')->get(['criterion_id', 'answer'])
            ->groupBy('criterion_id')
            ->map(fn (Collection $answers, int $id): array => ['label' => $labels[$id] ?? 'Kriteria '.$id, 'fulfilled' => $answers->where('answer', 'ya')->count(), 'answered' => $answers->count()])
            ->map(fn (array $row): array => $row + ['percent' => (int) round(100 * $row['fulfilled'] / $row['answered'])])
            ->sortByDesc('percent')
            ->values();
    }

    /**
     * Temuan dalam kalimat; hanya yang datanya cukup yang ditampilkan.
     *
     * @return list<array{icon: string, text: string}>
     */
    private function insights(Collection $assignments, array $kpis, Collection $byProgram, Collection $byCity, Collection $criteria, Collection $verified): array
    {
        if ($kpis['total'] === 0) {
            return [['icon' => 'circle', 'text' => 'Belum ada penugasan pada filter ini.']];
        }
        $percent = fn (int $part, int $whole): int => $whole ? (int) round(100 * $part / $whole) : 0;
        $items = [['icon' => 'clipboard-check', 'text' => "{$kpis['submitted']} dari {$kpis['total']} mahasiswa ({$percent($kpis['submitted'], $kpis['total'])}%) sudah disurvey dan dikirim.".($kpis['waiting'] ? " {$kpis['waiting']} masih menunggu keputusan verifikator." : '')]];
        if ($kpis['verified'] > 0) {
            $items[] = ['icon' => 'shield-check', 'text' => "{$percent($kpis['recommended'], $kpis['verified'])}% mahasiswa yang sudah diverifikasi direkomendasikan ({$kpis['recommended']} dari {$kpis['verified']})."];
        }
        $rates = $byProgram->map(fn (Collection $c): array => ['decided' => $c['direkomendasikan'] + $c['perlu_tinjauan'] + $c['tidak_direkomendasikan'], 'recommended' => $c['direkomendasikan']])
            ->filter(fn (array $r): bool => $r['decided'] > 0)
            ->map(fn (array $r): int => $percent($r['recommended'], $r['decided']));
        if ($rates->count() >= 2 && $rates->max() !== $rates->min()) {
            $highest = $rates->sortDesc()->keys()->first();
            $lowest = $rates->sort()->keys()->first();
            $items[] = ['icon' => 'graduation-cap', 'text' => "Proporsi rekomendasi tertinggi di {$highest} ({$rates[$highest]}%), terendah di {$lowest} ({$rates[$lowest]}%)."];
        }
        $remaining = $byCity->map(fn (Collection $c): int => $c['belum_selesai'])->filter()->sortDesc();
        if ($remaining->isNotEmpty()) {
            $items[] = ['icon' => 'map-pin', 'text' => "Sisa survey terbanyak di {$remaining->keys()->first()}: {$remaining->first()} mahasiswa belum selesai."];
        }
        if ($criteria->count() >= 2) {
            $top = $criteria->first();
            $low = $criteria->last();
            $items[] = ['icon' => 'file-check-2', 'text' => 'Kriteria paling sering terpenuhi: “'.Str::limit($top['label'], 80, '…', true)."” ({$top['percent']}%). Paling jarang: “".Str::limit($low['label'], 80, '…', true)."” ({$low['percent']}%)."];
        }
        $recommended = $assignments->filter(fn (SurveyAssignment $a): bool => $a->resultCategory() === 'direkomendasikan');
        if ($recommended->isNotEmpty()) {
            $withPkh = $recommended->filter(fn (SurveyAssignment $a): bool => (bool) $a->survey->pkh)->count();
            $items[] = ['icon' => 'check-circle-2', 'text' => "{$withPkh} dari {$recommended->count()} mahasiswa direkomendasikan ({$percent($withPkh, $recommended->count())}%) sudah memiliki kartu PKH."];
        }
        $failures = SurveyFailure::whereIn('survey_assignment_id', $assignments->pluck('id'))->get(['reason']);
        if ($failures->isNotEmpty()) {
            $reasons = $failures->countBy(fn (SurveyFailure $f): string => Str::lower($f->reason->label()));
            $top = $reasons->filter(fn (int $count): bool => $count === $reasons->max())->keys();
            $items[] = ['icon' => 'circle-x', 'text' => "{$failures->count()} kunjungan gagal tercatat; alasan terbanyak: ".$top->join(', ', ' dan ').($top->count() > 1 ? " (masing-masing {$reasons->max()})." : " ({$reasons->max()}).")];
        }
        $surveyIds = $assignments->pluck('survey.id')->filter();
        $revised = SurveyVerification::whereIn('survey_id', $surveyIds)->where('decision', 'revision')->distinct()->count('survey_id');
        if ($revised > 0) {
            $items[] = ['icon' => 'history', 'text' => "{$revised} survey pernah dikembalikan untuk revisi."];
        }
        $hours = $verified->map(fn (Survey $s): ?float => $s->latestVerification ? $s->submitted_at->diffInMinutes($s->latestVerification->created_at) / 60 : null)->filter(fn (?float $h): bool => $h !== null && $h >= 0);
        if ($hours->isNotEmpty()) {
            $average = $hours->avg();
            $duration = match (true) {
                $average < 1 => 'kurang dari 1 jam',
                $average < 24 => number_format($average, 1, ',', '.').' jam',
                default => number_format($average / 24, 1, ',', '.').' hari',
            };
            $items[] = ['icon' => 'clock-3', 'text' => "Rata-rata waktu verifikasi {$duration} sejak survey dikirim."];
        }

        return $items;
    }
}
