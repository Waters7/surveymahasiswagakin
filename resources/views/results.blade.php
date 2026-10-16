@extends('layouts.app')
@section('title', 'Dashboard hasil survey')
@section('content')
@php
    $percent = fn (int $part, int $whole): int => $whole ? (int) round(100 * $part / $whole) : 0;
    $exportQuery = http_build_query($filters);
    $legendStatus = ['direkomendasikan' => 'direkomendasikan', 'perlu_tinjauan' => 'terverifikasi', 'tidak_direkomendasikan' => 'tidak_direkomendasikan', 'dalam_verifikasi' => 'belum_verifikasi', 'belum_selesai' => 'belum_dikirim'];
    $compositionLabel = collect($labels)->map(fn (string $label, string $key): string => $label.' '.$composition[$key])->implode(', ');
@endphp
<div class="page-heading" style="flex-wrap:wrap">
    <div><h1>Dashboard hasil survey</h1><p>Hasil dihitung dari keputusan verifikator; survey yang belum diputuskan masuk “Dalam verifikasi”.</p></div>
    <div class="heading-actions"><a class="btn" href="{{route('results.students', $filters)}}"><x-icon name="graduation-cap" size="16"/>Data mahasiswa</a><a class="btn" href="/reports/excel?{{$exportQuery}}"><x-icon name="download" size="16"/>Unduh Excel</a></div>
</div>
<form class="filter-bar" method="get" action="{{route('results.index')}}">
    <div class="field"><label for="result-program">Prodi</label><select id="result-program" name="program"><option value="">Semua prodi</option>@foreach($programs as $program)<option value="{{$program->id}}" @selected((string) ($filters['program'] ?? '') === (string) $program->id)>{{$program->name}}</option>@endforeach</select></div>
    <div class="field"><label for="result-city">Kabupaten/kota</label><select id="result-city" name="city"><option value="">Semua wilayah</option>@foreach($cities as $city)<option @selected(($filters['city'] ?? '') === $city)>{{$city}}</option>@endforeach</select></div>
    <div class="field"><label for="result-method">Metode</label><select id="result-method" name="method"><option value="">Semua metode</option>@foreach(\App\Enums\SurveyMethod::cases() as $method)<option value="{{$method->value}}" @selected(($filters['method'] ?? '') === $method->value)>{{$method->label()}}</option>@endforeach</select></div>
    <div class="field"><label for="result-period">Periode</label><select id="result-period" name="survey_period_id"><option value="">Semua periode</option>@foreach($periods as $period)<option value="{{$period->id}}" @selected((string) ($filters['survey_period_id'] ?? '') === (string) $period->id)>{{$period->name}}</option>@endforeach</select></div>
    <button class="btn primary">Terapkan</button><a class="btn" href="{{route('results.index')}}">Reset</a>
</form>
<div class="result-kpis">
    @foreach([
        ['Total penugasan', $kpis['total'], 'Mahasiswa dalam cakupan filter', null],
        ['Sudah dikirim', $kpis['submitted'], $percent($kpis['submitted'], $kpis['total']).'% dari penugasan', null],
        ['Sudah diverifikasi', $kpis['verified'], $percent($kpis['verified'], $kpis['submitted']).'% dari yang dikirim', 'terverifikasi'],
        ['Direkomendasikan', $kpis['recommended'], $percent($kpis['recommended'], $kpis['verified']).'% dari yang diverifikasi', 'direkomendasikan'],
        ['Menunggu verifikasi', $kpis['waiting'], 'Termasuk yang sedang direvisi', 'belum_verifikasi'],
    ] as [$label, $value, $note, $status])
        <a class="card kpi result-kpi" href="{{route('results.students', array_filter(['status' => $status] + $filters))}}"><span class="kpi-top">{{$label}}</span><strong class="kpi-number">{{number_format($value, 0, ',', '.')}}</strong><span class="kpi-note">{{$note}}</span></a>
    @endforeach
</div>
<div class="grid-two result-overview">
    <section class="card">
        <div class="card-header"><div><h2>Temuan utama</h2><p>Dirangkum otomatis dari data sesuai filter.</p></div></div>
        <ul class="insight-list">
            @foreach($insights as $insight)
                <li><span class="insight-icon"><x-icon :name="$insight['icon']" size="16"/></span><span>{{$insight['text']}}</span></li>
            @endforeach
        </ul>
    </section>
    <section class="card">
        <div class="card-header"><div><h2>Komposisi hasil</h2><p>{{number_format($kpis['total'], 0, ',', '.')}} mahasiswa</p></div></div>
        <div class="card-body">
            <div class="result-stack result-stack-lg" role="img" aria-label="Komposisi hasil: {{$compositionLabel}}">
                @foreach($labels as $key => $label)
                    @if($composition[$key])<span class="result-seg result-seg-{{$key}}" style="flex-grow:{{$composition[$key]}}" tabindex="0" data-tip="{{$label}}: {{$composition[$key]}} ({{$percent($composition[$key], $kpis['total'])}}%)"></span>@endif
                @endforeach
                @if(! $kpis['total'])<span class="result-seg result-seg-belum_selesai" style="flex-grow:1"></span>@endif
            </div>
            <ul class="result-legend">
                @foreach($labels as $key => $label)
                    @continue($key === 'perlu_tinjauan' && ! $composition[$key])
                    <li><span class="result-swatch result-seg-{{$key}}" aria-hidden="true"></span><a href="{{route('results.students', ['status' => $legendStatus[$key]] + $filters)}}">{{$label}}</a><strong>{{$composition[$key]}}</strong><small>{{$percent($composition[$key], $kpis['total'])}}%</small></li>
                @endforeach
            </ul>
        </div>
    </section>
</div>
<div class="grid-two result-breakdowns">
    @foreach(['Per program studi' => $byProgram, 'Per kabupaten/kota' => $byCity] as $title => $groups)
        <section class="card">
            <div class="card-header"><div><h2>{{$title}}</h2><p>Warna batang mengikuti legenda komposisi hasil.</p></div></div>
            <div class="table-wrap"><table class="result-breakdown"><thead><tr><th>{{$loop->first ? 'Prodi' : 'Wilayah'}}</th><th>Rekom.</th><th>Tidak</th><th>Verifikasi</th><th>Belum</th><th>Total</th></tr></thead><tbody>
            @forelse($groups as $name => $counts)
                <tr><td><strong>{{$name}}</strong><div class="result-stack" aria-hidden="true">@foreach($labels as $key => $label)@if($counts[$key])<span class="result-seg result-seg-{{$key}}" style="flex-grow:{{$counts[$key]}}" data-tip="{{$label}}: {{$counts[$key]}}"></span>@endif @endforeach</div></td><td>{{$counts['direkomendasikan']}}</td><td>{{$counts['tidak_direkomendasikan']}}</td><td>{{$counts['dalam_verifikasi'] + $counts['perlu_tinjauan']}}</td><td>{{$counts['belum_selesai']}}</td><td>{{$counts['total']}}</td></tr>
            @empty
                <tr><td colspan="6" class="empty">Belum ada penugasan.</td></tr>
            @endforelse
            </tbody></table></div>
        </section>
    @endforeach
</div>
<section class="card result-criteria">
    <div class="card-header"><div><h2>Kriteria yang terpenuhi</h2><p>Persentase jawaban “ya” per kriteria pada survey yang sudah dikirim, dari yang paling sering terpenuhi.</p></div></div>
    <div class="card-body">
        @forelse($criteria as $criterion)
            <div class="criteria-row" tabindex="0" data-tip="{{$criterion['fulfilled']}} dari {{$criterion['answered']}} survey menjawab ya">
                <span class="criteria-label">{{\Illuminate\Support\Str::limit($criterion['label'], 110, '…', true)}}</span>
                <span class="criteria-track"><span class="criteria-bar" style="width:{{max($criterion['percent'], 1)}}%"></span></span>
                <span class="criteria-value">{{$criterion['percent']}}%</span>
            </div>
        @empty
            <div class="chart-empty"><div><x-icon name="chart-bar" size="30"/><p>Grafik kriteria muncul setelah ada survey yang dikirim.</p></div></div>
        @endforelse
    </div>
</section>
@endsection
