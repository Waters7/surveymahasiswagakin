@extends('layouts.app')
@section('title', 'Verifikasi')
@section('content')
<div class="page-heading" style="flex-wrap:wrap">
    <div><h1>Verifikasi survey</h1><p>Periksa hasil survey lokasi dan video call, lalu setujui, tolak, atau kembalikan ke surveyor untuk revisi.</p></div>
</div>
<nav class="user-summary verify-summary" aria-label="Status verifikasi">
    @foreach($tabs as $value => $label)
        <a class="card user-stat {{$status === $value ? 'active' : ''}}" href="{{route('verifications.index', array_filter(['status' => $value] + \Illuminate\Support\Arr::except($filters, 'status')))}}" @if($status === $value) aria-current="true" @endif><span>{{$label}}</span><strong>{{$counts[$value] ?? 0}}</strong></a>
    @endforeach
</nav>
<form class="filter-bar" method="get" action="{{route('verifications.index')}}">
    <input type="hidden" name="status" value="{{$status}}">
    <div class="field"><label for="verify-search">Cari mahasiswa</label><input id="verify-search" name="q" value="{{$filters['q'] ?? ''}}" placeholder="Nama atau NIM"></div>
    <div class="field"><label for="verify-method">Metode</label><select id="verify-method" name="method"><option value="">Semua metode</option>@foreach(\App\Enums\SurveyMethod::cases() as $method)<option value="{{$method->value}}" @selected(($filters['method'] ?? '') === $method->value)>{{$method->label()}}</option>@endforeach</select></div>
    <div class="field"><label for="verify-surveyor">Surveyor</label><select id="verify-surveyor" name="user_id"><option value="">Semua surveyor</option>@foreach($surveyors as $surveyor)<option value="{{$surveyor->id}}" @selected((string) ($filters['user_id'] ?? '') === (string) $surveyor->id)>{{$surveyor->name}}</option>@endforeach</select></div>
    <button class="btn primary">Terapkan</button><a class="btn" href="{{route('verifications.index', ['status' => $status])}}">Reset</a>
</form>
<section class="card">
    <div class="card-header"><div><h2>{{$tabs[$status]}}</h2><p>{{$surveys->total()}} survey{{$status === 'menunggu_verifikasi' ? ', diurutkan dari kiriman paling lama' : ''}}.</p></div></div>
    <div class="table-wrap"><table class="user-table verify-table"><thead><tr><th>Mahasiswa</th><th>Surveyor</th><th>Metode</th><th>Dikirim</th><th>Catatan terakhir</th><th><span class="sr-only">Aksi</span></th></tr></thead><tbody>
    @forelse($surveys as $survey)
        <tr>
            <td data-label="Mahasiswa"><div class="student-name"><span class="avatar">{{mb_substr($survey->student->name, 0, 1)}}</span><div><strong>{{$survey->student->name}}</strong><small>{{$survey->student->nim}} · {{$survey->student->program?->name}}</small></div></div></td>
            <td data-label="Surveyor">{{$survey->user->name}}@if($survey->user_id === auth()->id())<small>Survey Anda</small>@endif</td>
            <td data-label="Metode"><span class="badge">{{($survey->method ?? \App\Enums\SurveyMethod::Location)->label()}}</span></td>
            <td data-label="Dikirim">@if($survey->submitted_at){{$survey->submitted_at->timezone('Asia/Makassar')->format('d M Y H:i')}}<small>WITA</small>@else<span class="muted">Belum dikirim</span>@endif</td>
            <td data-label="Catatan terakhir">@if($survey->latestVerification)<span class="verify-note">{{\Illuminate\Support\Str::limit($survey->latestVerification->reason, 90)}}</span><small>{{$survey->latestVerification->user?->name}} · {{$survey->latestVerification->created_at->timezone('Asia/Makassar')->format('d M Y H:i')}}</small>@else<span class="muted">Belum ada</span>@endif</td>
            <td class="row-actions">
                @can('verify', $survey)
                    <a class="btn primary" href="/surveys/{{$survey->uuid}}#verifikasi"><x-icon name="shield-check" size="15"/>Periksa</a>
                @elsecan('reopen', $survey)
                    <a class="btn" href="/surveys/{{$survey->uuid}}#verifikasi">Lihat · buka revisi</a>
                @else
                    <a class="btn" href="/surveys/{{$survey->uuid}}">Lihat</a>
                @endcan
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="empty">Tidak ada survey dengan status ini.</td></tr>
    @endforelse
    </tbody></table></div>
    <div class="pagination">{{$surveys->links()}}</div>
</section>
@endsection
