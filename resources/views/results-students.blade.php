@extends('layouts.app')
@section('title', 'Data mahasiswa survey')
@section('content')
@php
    $resultClass = ['direkomendasikan' => 'success', 'tidak_direkomendasikan' => 'warning', 'perlu_tinjauan' => 'warning'];
    $withoutStatus = \Illuminate\Support\Arr::except($filters, 'status');
@endphp
<div class="page-heading" style="flex-wrap:wrap">
    <div><h1>Data mahasiswa survey</h1><p>Saring mahasiswa berdasarkan rekomendasi dan status verifikasi, lalu buka hasil atau PDF-nya.</p></div>
    <div class="heading-actions"><a class="btn" href="/reports/excel?{{http_build_query($withoutStatus)}}"><x-icon name="download" size="16"/>Unduh Excel</a></div>
</div>
<nav class="user-summary result-summary" aria-label="Status hasil mahasiswa">
    <a class="card user-stat {{empty($filters['status']) ? 'active' : ''}}" href="{{route('results.students', $withoutStatus)}}" @if(empty($filters['status'])) aria-current="true" @endif><span>Semua</span><strong>{{$total}}</strong></a>
    @foreach($statusFilters as $key => $label)
        <a class="card user-stat {{($filters['status'] ?? '') === $key ? 'active' : ''}}" href="{{route('results.students', ['status' => $key] + $withoutStatus)}}" @if(($filters['status'] ?? '') === $key) aria-current="true" @endif><span>{{$label}}</span><strong>{{$counts[$key]}}</strong></a>
    @endforeach
</nav>
<form class="filter-bar" method="get" action="{{route('results.students')}}">
    @if(! empty($filters['status']))<input type="hidden" name="status" value="{{$filters['status']}}">@endif
    <div class="field"><label for="student-search">Cari mahasiswa</label><input id="student-search" name="q" value="{{$filters['q'] ?? ''}}" placeholder="Nama, NIM, HP, alamat"></div>
    <div class="field"><label for="student-recommendation">Rekomendasi surveyor</label><select id="student-recommendation" name="recommendation"><option value="">Semua rekomendasi</option>@foreach(\App\Enums\Recommendation::cases() as $recommendation)<option value="{{$recommendation->value}}" @selected(($filters['recommendation'] ?? '') === $recommendation->value)>{{ucfirst(str_replace('_', ' ', $recommendation->value))}}</option>@endforeach</select></div>
    <div class="field"><label for="student-program">Prodi</label><select id="student-program" name="program"><option value="">Semua prodi</option>@foreach($programs as $program)<option value="{{$program->id}}" @selected((string) ($filters['program'] ?? '') === (string) $program->id)>{{$program->name}}</option>@endforeach</select></div>
    <div class="field"><label for="student-city">Kabupaten/kota</label><select id="student-city" name="city"><option value="">Semua wilayah</option>@foreach($cities as $city)<option @selected(($filters['city'] ?? '') === $city)>{{$city}}</option>@endforeach</select></div>
    <div class="field"><label for="student-method">Metode</label><select id="student-method" name="method"><option value="">Semua metode</option>@foreach(\App\Enums\SurveyMethod::cases() as $method)<option value="{{$method->value}}" @selected(($filters['method'] ?? '') === $method->value)>{{$method->label()}}</option>@endforeach</select></div>
    <div class="field"><label for="student-period">Periode</label><select id="student-period" name="survey_period_id"><option value="">Semua periode</option>@foreach($periods as $period)<option value="{{$period->id}}" @selected((string) ($filters['survey_period_id'] ?? '') === (string) $period->id)>{{$period->name}}</option>@endforeach</select></div>
    <button class="btn primary">Terapkan</button><a class="btn" href="{{route('results.students')}}">Reset</a>
</form>
<section class="card">
    <div class="card-header"><div><h2>{{empty($filters['status']) ? 'Semua mahasiswa' : $statusFilters[$filters['status']]}}</h2><p>{{$assignments->total()}} mahasiswa sesuai filter.</p></div></div>
    <div class="table-wrap"><table class="user-table result-table"><thead><tr><th>Mahasiswa</th><th>Hasil</th><th>Verifikasi</th><th>Kriteria terpenuhi</th><th>PKH</th><th>Surveyor</th><th><span class="sr-only">Aksi</span></th></tr></thead><tbody>
    @forelse($assignments as $a)
        @php($result = $a->resultCategory())
        <tr>
            <td data-label="Mahasiswa"><div class="student-name"><span class="avatar">{{mb_substr($a->student->name, 0, 1)}}</span><div><strong>{{$a->student->name}}</strong><small>{{$a->student->nim}} · {{$a->student->program->name}} · {{$a->student->address?->city ?: 'Wilayah belum diisi'}}</small></div></div></td>
            <td data-label="Hasil"><span class="badge {{$resultClass[$result] ?? ''}}">{{$labels[$result]}}</span>@if($a->survey?->recommendation && $a->survey->submitted_at)<small>Rekomendasi surveyor: {{str_replace('_', ' ', $a->survey->recommendation)}}</small>@endif</td>
            <td data-label="Verifikasi">@if($a->survey?->latestVerification && in_array($a->survey->status, ['disetujui', 'tidak_direkomendasikan'], true))Sudah<small>{{$a->survey->latestVerification->created_at->timezone('Asia/Makassar')->format('d M Y')}}</small>@elseif($a->survey?->submitted_at)Belum<small>{{str_replace('_', ' ', $a->survey->status)}}</small>@else<span class="muted">Belum dikirim</span><small>{{str_replace('_', ' ', $a->survey?->status ?? $a->status)}}</small>@endif</td>
            <td data-label="Kriteria terpenuhi">@if($a->survey?->submitted_at){{$a->survey->fulfilled_count}} / {{$a->survey->answers_count}}@else<span class="muted">—</span>@endif</td>
            <td data-label="PKH">@if($a->survey?->submitted_at && $a->survey->pkh !== null){{$a->survey->pkh ? 'Ya' : 'Tidak'}}@else<span class="muted">—</span>@endif</td>
            <td data-label="Surveyor">{{$a->user->name}}<small>{{($a->method ?? \App\Enums\SurveyMethod::Location)->label()}}</small></td>
            <td class="row-actions">@if($a->survey)<a class="btn" href="/surveys/{{$a->survey->uuid}}">Lihat</a>@if($a->survey->locked_at)<a class="btn" href="/surveys/{{$a->survey->uuid}}/pdf" target="_blank" rel="noopener" aria-label="PDF {{$a->student->name}}">PDF</a>@endif @else<span class="muted">Belum disurvey</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="7" class="empty">Tidak ada mahasiswa sesuai filter.</td></tr>
    @endforelse
    </tbody></table></div>
    <div class="pagination">{{$assignments->links()}}</div>
</section>
@endsection
