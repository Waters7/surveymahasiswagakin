@extends('layouts.app')
@section('title', 'Hasil survey')
@section('content')
@php
    $exportQuery = http_build_query(\Illuminate\Support\Arr::except($filters, 'result'));
    $percent = fn (int $count): int => $total ? (int) round(100 * $count / $total) : 0;
    $resultClass = ['direkomendasikan' => 'success', 'tidak_direkomendasikan' => 'warning', 'perlu_tinjauan' => 'warning'];
@endphp
<div class="page-heading" style="flex-wrap:wrap">
    <div><h1>Hasil survey</h1><p>Rekap keputusan akhir setelah verifikasi. Hasil baru dihitung bila verifikator sudah memutuskan.</p></div>
    <div class="heading-actions"><a class="btn" href="/reports/excel?{{$exportQuery}}"><x-icon name="download" size="16"/>Unduh Excel</a></div>
</div>
<nav class="user-summary result-summary" aria-label="Ringkasan hasil survey">
    <a class="card user-stat {{empty($filters['result']) ? 'active' : ''}}" href="{{route('results.index', \Illuminate\Support\Arr::except($filters, 'result'))}}"><span>Total penugasan</span><strong>{{$total}}</strong></a>
    @foreach($labels as $key => $label)
        @continue($key === 'perlu_tinjauan' && ! $summary[$key])
        <a class="card user-stat {{($filters['result'] ?? '') === $key ? 'active' : ''}}" href="{{route('results.index', ['result' => $key] + $filters)}}" @if(($filters['result'] ?? '') === $key) aria-current="true" @endif><span>{{$label}}</span><strong>{{$summary[$key]}}</strong><small>{{$percent($summary[$key])}}% dari penugasan</small></a>
    @endforeach
</nav>
<form class="filter-bar" method="get" action="{{route('results.index')}}">
    @if(! empty($filters['result']))<input type="hidden" name="result" value="{{$filters['result']}}">@endif
    <div class="field"><label for="result-search">Cari mahasiswa</label><input id="result-search" name="q" value="{{$filters['q'] ?? ''}}" placeholder="Nama, NIM, HP, alamat"></div>
    <div class="field"><label for="result-program">Prodi</label><select id="result-program" name="program"><option value="">Semua prodi</option>@foreach($programs as $program)<option value="{{$program->id}}" @selected((string) ($filters['program'] ?? '') === (string) $program->id)>{{$program->name}}</option>@endforeach</select></div>
    <div class="field"><label for="result-city">Kabupaten/kota</label><select id="result-city" name="city"><option value="">Semua wilayah</option>@foreach($cities as $city)<option @selected(($filters['city'] ?? '') === $city)>{{$city}}</option>@endforeach</select></div>
    <div class="field"><label for="result-method">Metode</label><select id="result-method" name="method"><option value="">Semua metode</option>@foreach(\App\Enums\SurveyMethod::cases() as $method)<option value="{{$method->value}}" @selected(($filters['method'] ?? '') === $method->value)>{{$method->label()}}</option>@endforeach</select></div>
    <div class="field"><label for="result-period">Periode</label><select id="result-period" name="survey_period_id"><option value="">Semua periode</option>@foreach($periods as $period)<option value="{{$period->id}}" @selected((string) ($filters['survey_period_id'] ?? '') === (string) $period->id)>{{$period->name}}</option>@endforeach</select></div>
    <button class="btn primary">Terapkan</button><a class="btn" href="{{route('results.index')}}">Reset</a>
</form>
<div class="grid-two result-breakdowns">
    @foreach(['Per program studi' => $byProgram, 'Per kabupaten/kota' => $byCity] as $title => $groups)
        <section class="card">
            <div class="card-header"><div><h2>{{$title}}</h2><p>Direkomendasikan · tidak · dalam verifikasi · belum selesai</p></div></div>
            <div class="table-wrap"><table class="result-breakdown"><thead><tr><th>{{$loop->first ? 'Prodi' : 'Wilayah'}}</th><th>Rekom.</th><th>Tidak</th><th>Verifikasi</th><th>Belum</th><th>Total</th></tr></thead><tbody>
            @forelse($groups as $name => $counts)
                <tr><td><strong>{{$name}}</strong><div class="result-bar" aria-hidden="true">@foreach(['direkomendasikan', 'tidak_direkomendasikan', 'perlu_tinjauan', 'dalam_verifikasi'] as $key)@if($counts[$key])<span class="result-bar-{{$key}}" style="width:{{100 * $counts[$key] / $counts['total']}}%"></span>@endif @endforeach</div></td><td>{{$counts['direkomendasikan']}}</td><td>{{$counts['tidak_direkomendasikan']}}</td><td>{{$counts['dalam_verifikasi'] + $counts['perlu_tinjauan']}}</td><td>{{$counts['belum_selesai']}}</td><td>{{$counts['total']}}</td></tr>
            @empty
                <tr><td colspan="6" class="empty">Belum ada penugasan.</td></tr>
            @endforelse
            </tbody></table></div>
        </section>
    @endforeach
</div>
<section class="card" style="margin-top:24px">
    <div class="card-header"><div><h2>{{empty($filters['result']) ? 'Semua hasil' : $labels[$filters['result']]}}</h2><p>{{$assignments->total()}} mahasiswa sesuai filter.</p></div></div>
    <div class="table-wrap"><table class="user-table result-table"><thead><tr><th>Mahasiswa</th><th>Hasil</th><th>Kriteria terpenuhi</th><th>PKH</th><th>Surveyor</th><th><span class="sr-only">Aksi</span></th></tr></thead><tbody>
    @forelse($assignments as $a)
        @php($result = $a->resultCategory())
        <tr>
            <td data-label="Mahasiswa"><div class="student-name"><span class="avatar">{{mb_substr($a->student->name, 0, 1)}}</span><div><strong>{{$a->student->name}}</strong><small>{{$a->student->nim}} · {{$a->student->program->name}} · {{$a->student->address?->city ?: 'Wilayah belum diisi'}}</small></div></div></td>
            <td data-label="Hasil"><span class="badge {{$resultClass[$result] ?? ''}}">{{$labels[$result]}}</span>@if($result === 'belum_selesai' || $result === 'dalam_verifikasi')<small>{{str_replace('_', ' ', $a->survey?->status ?? $a->status)}}</small>@endif</td>
            <td data-label="Kriteria terpenuhi">@if($a->survey?->submitted_at){{$a->survey->fulfilled_count}} / {{$a->survey->answers_count}}@else<span class="muted">—</span>@endif</td>
            <td data-label="PKH">@if($a->survey?->submitted_at && $a->survey->pkh !== null){{$a->survey->pkh ? 'Ya' : 'Tidak'}}@else<span class="muted">—</span>@endif</td>
            <td data-label="Surveyor">{{$a->user->name}}<small>{{($a->method ?? \App\Enums\SurveyMethod::Location)->label()}}</small></td>
            <td class="row-actions">@if($a->survey)<a class="btn" href="/surveys/{{$a->survey->uuid}}">Lihat</a>@if($a->survey->locked_at)<a class="btn" href="/surveys/{{$a->survey->uuid}}/pdf" target="_blank" rel="noopener" aria-label="PDF {{$a->student->name}}">PDF</a>@endif @else<span class="muted">Belum disurvey</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="6" class="empty">Tidak ada mahasiswa sesuai filter.</td></tr>
    @endforelse
    </tbody></table></div>
    <div class="pagination">{{$assignments->links()}}</div>
</section>
@endsection
