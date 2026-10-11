@extends('layouts.app')
@section('title', 'Laporan survey')
@section('content')
<div class="page-heading" style="flex-wrap:wrap">
    <div><h1>Laporan survey</h1><p>Cetak seluruh hasil kunjungan berdasarkan surveyor dan periode.</p></div>
    @if($summaries->sum('ready') > 0)<a class="btn primary" href="{{route('reports.print', $filters)}}" target="_blank" rel="noopener"><x-icon name="printer" size="16"/>Cetak semua hasil filter ({{$summaries->sum('ready')}})</a>@endif
</div>
<form class="filter-bar" method="get" action="{{route('reports.index')}}">
    @can('surveys.view-all')<div class="field"><label for="report-surveyor">Surveyor</label><select id="report-surveyor" name="user_id"><option value="">Semua surveyor</option>@foreach($surveyors as $surveyor)<option value="{{$surveyor->id}}" @selected(($filters['user_id'] ?? '') == $surveyor->id)>{{$surveyor->name}}</option>@endforeach</select></div>@endcan
    <div class="field"><label for="report-period">Periode survey</label><select id="report-period" name="survey_period_id"><option value="">Semua periode</option>@foreach($periods as $period)<option value="{{$period->id}}" @selected(($filters['survey_period_id'] ?? '') == $period->id)>{{$period->name}}</option>@endforeach</select></div>
    <button class="btn primary">Terapkan</button><a class="btn" href="{{route('reports.index')}}">Reset</a>
</form>
<div class="hint">Hanya hasil yang sudah dikirim dan terkunci yang masuk cetakan. Hasil menunggu verifikasi tetap disertakan dengan statusnya. Draft dan revisi belum selesai tidak masuk cetakan.</div>
<div class="kpi-grid">
    <article class="card kpi"><div class="kpi-top">Survey dilakukan</div><div class="kpi-number">{{$surveys->total()}}</div><div class="kpi-note">Sesuai filter saat ini</div></article>
    <article class="card kpi"><div class="kpi-top">Siap dicetak</div><div class="kpi-number">{{$summaries->sum('ready')}}</div><div class="kpi-note">Hasil terkirim dan terkunci</div></article>
    <article class="card kpi"><div class="kpi-top">Draft / perlu revisi</div><div class="kpi-number">{{$summaries->sum('unfinished')}}</div><div class="kpi-note">Belum masuk cetakan</div></article>
    <article class="card kpi"><div class="kpi-top">Surveyor</div><div class="kpi-number">{{$summaries->count()}}</div><div class="kpi-note">Memiliki hasil sesuai filter</div></article>
</div>
<h2 style="margin-bottom:16px">Cetak per surveyor</h2>
<div class="task-grid" style="margin-bottom:24px">
    @forelse($summaries as $summary)
    <article class="card task-card"><h3 style="margin-top:0">{{$summary['user']->name}}</h3><p>{{$summary['ready']}} hasil siap dicetak</p><p class="muted">{{$summary['unfinished']}} draft / perlu revisi</p>
        @if($summary['ready'] > 0)<div class="task-actions"><a class="btn primary" href="{{route('reports.print', array_merge($filters, ['user_id' => $summary['user']->id]))}}" target="_blank" rel="noopener"><x-icon name="printer" size="16"/>Cetak hasil surveyor</a></div>@endif
    </article>
    @empty<div class="card empty" style="grid-column:1/-1">Belum ada survey yang dilakukan sesuai filter.</div>@endforelse
</div>
<section class="card">
    <div class="card-header"><div><h2>Daftar hasil survey</h2><p>Formulir lengkap dan lampiran dapat dilihat sebelum dicetak.</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Mahasiswa</th><th>Surveyor / periode</th><th>Terkirim</th><th>Status</th><th>Hasil</th></tr></thead><tbody>
    @forelse($surveys as $survey)<tr>
        <td><strong>{{$survey->student_snapshot['name']}}</strong><small>{{$survey->student_snapshot['nim'] ?? $survey->student_snapshot['registration_number'] ?? '—'}}</small></td>
        <td>{{$survey->user->name}}<small>{{$survey->assignment?->period?->name}}</small></td>
        <td>{{$survey->submitted_at?->format('d-m-Y H:i') ?? 'Belum dikirim'}}@if($survey->submitted_at)<small>WITA</small>@endif</td>
        <td><x-badge :status="$survey->status"/>@if(!$survey->locked_at)<small>Belum masuk cetakan</small>@endif</td>
        <td><a class="btn" href="{{url('/surveys/'.$survey->uuid)}}">Lihat hasil</a></td>
    </tr>@empty<tr><td colspan="5" class="empty">Belum ada hasil survey.</td></tr>@endforelse
    </tbody></table></div>
    <div class="pagination">{{$surveys->links()}}</div>
</section>
@endsection
