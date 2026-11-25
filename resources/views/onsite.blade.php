@extends('layouts.app')
@section('title', 'Wawancara onsite')
@section('content')
@php
    $badgeClass = ['tersedia' => '', 'diambil' => 'warning', 'berlangsung' => 'warning', 'selesai' => 'success'];
    $user = auth()->user();
    $baseFilters = \Illuminate\Support\Arr::except($filters, 'status');
@endphp
<div class="page-heading" style="flex-wrap:wrap">
    <div><h1>Wawancara onsite</h1><p>Mahasiswa diwawancarai langsung di kampus. Ambil mahasiswa yang akan Anda wawancarai; mahasiswa yang sudah diambil tidak dapat diambil petugas lain.</p></div>
</div>
<nav class="method-tabs" aria-label="Metode survey"><a href="{{route('tasks.location')}}"><x-icon name="map-pin" size="15"/>Survey lokasi</a><a href="{{route('tasks.video-call')}}"><x-icon name="video" size="15"/>Survey video call</a><a href="{{route('tasks.onsite')}}" class="active" aria-current="page"><x-icon name="users" size="15"/>Wawancara onsite</a></nav>
<nav class="user-summary onsite-summary" aria-label="Tahap wawancara">
    @foreach($tabs as $key => $label)
        @continue($key === 'saya' && ! $user->can('surveys.work'))
        <a class="card user-stat {{$status === $key ? 'active' : ''}}" href="{{route('tasks.onsite', ['status' => $key] + $baseFilters)}}" @if($status === $key) aria-current="true" @endif><span>{{$label}}</span><strong>{{$counts[$key]}}</strong></a>
    @endforeach
</nav>
<form class="filter-bar" method="get" action="{{route('tasks.onsite')}}">
    <input type="hidden" name="status" value="{{$status}}">
    <div class="field"><label for="onsite-search">Cari mahasiswa</label><input id="onsite-search" name="q" value="{{$filters['q'] ?? ''}}" placeholder="Nama atau NIM"></div>
    <div class="field"><label for="onsite-program">Prodi</label><select id="onsite-program" name="program"><option value="">Semua prodi</option>@foreach($programs as $program)<option value="{{$program->id}}" @selected((string) ($filters['program'] ?? '') === (string) $program->id)>{{$program->name}}</option>@endforeach</select></div>
    <button class="btn primary">Terapkan</button><a class="btn" href="{{route('tasks.onsite', ['status' => $status])}}">Reset</a>
</form>
<p class="muted onsite-count">{{$interviews->total()}} mahasiswa · {{$tabs[$status]}}@if($status === 'belum_selesai') · tugas Anda ditampilkan paling atas @endif</p>
<div class="onsite-list">
    @forelse($interviews as $interview)
        @php
            $state = $interview->status();
            $mine = $interview->claimed_by === $user->id;
            $other = $interview->otherAssignment();
            $survey = $interview->claimed_by ? $interview->assignment?->survey : $other?->survey;
        @endphp
        <article class="card onsite-card onsite-{{$state}} {{$mine ? 'onsite-mine' : ''}}">
            <div class="onsite-main">
                <div class="student-name"><span class="avatar">{{mb_substr($interview->student->name, 0, 1)}}</span><div><strong>{{$interview->student->name}}</strong><small>{{$interview->student->nim}} · {{$interview->student->program?->name}}</small></div></div>
                @if($interview->notes)<p class="onsite-note"><x-icon name="message-circle" size="14"/><span>{{$interview->notes}}</span></p>@endif
                @if($state === 'tersedia' && $other)<p class="onsite-hint">Saat ini ditugaskan {{\Illuminate\Support\Str::lower(($other->method ?? \App\Enums\SurveyMethod::Location)->label())}} ke {{$other->user->name}}; dialihkan ke petugas yang mengambil.</p>@endif
                @if($interview->student->phone)<div class="task-info"><x-icon name="phone" size="15"/><x-phone-contact :number="$interview->student->phone" whatsapp/></div>@endif
            </div>
            <div class="onsite-status">
                <span class="badge {{$badgeClass[$state]}}">{{\App\Models\OnsiteInterview::STATUS_LABELS[$state]}}</span>
                @if($interview->claimed_by)<small>{{$mine ? 'Anda' : $interview->claimer?->name}} · {{$interview->claimed_at?->timezone('Asia/Makassar')->format('d M H:i')}}</small>@endif
                @if($state === 'selesai' && $survey)<small>{{ucfirst(str_replace('_', ' ', $survey->status))}}@unless($interview->claimed_by) · {{\Illuminate\Support\Str::lower(($other->method ?? \App\Enums\SurveyMethod::Location)->label())}}@endunless</small>@endif
            </div>
            <div class="onsite-actions">
                @if($state === 'tersedia' && $user->can('surveys.work'))
                    <form method="post" action="{{route('onsite.claim', $interview)}}">@csrf<button class="btn primary"><x-icon name="user-plus" size="16"/>Ambil untuk wawancara</button></form>
                @elseif($mine && $state === 'diambil')
                    <form method="post" action="/assignments/{{$interview->assignment->uuid}}/start">@csrf<button class="btn primary"><x-icon name="clipboard-check" size="16"/>Mulai wawancara</button></form>
                    <form method="post" action="{{route('onsite.release', $interview)}}">@csrf<button class="btn">Lepas</button></form>
                @elseif($mine && $state === 'berlangsung')
                    <a class="btn primary" href="/surveys/{{$survey->uuid}}">{{$survey->status === 'perlu_revisi' ? 'Perbaiki revisi' : 'Lanjutkan'}}<x-icon name="arrow-right" size="14"/></a>
                @elseif($state === 'diambil' && $user->can('assignments.manage'))
                    <form method="post" action="{{route('onsite.release', $interview)}}">@csrf<button class="btn">Kembalikan ke daftar</button></form>
                @endif
                @if($state === 'selesai' && $survey)
                    @can('view', $survey)<a class="btn" href="/surveys/{{$survey->uuid}}">Lihat hasil</a>@endcan
                @endif
            </div>
            @if($mine && $interview->assignment && $state !== 'selesai')<div class="onsite-failure"><x-survey-failure :assignment="$interview->assignment"/></div>@endif
        </article>
    @empty
        <div class="card empty">@if($status === 'belum_selesai')Semua wawancara onsite sudah selesai.@else Tidak ada mahasiswa pada filter ini.@endif</div>
    @endforelse
</div>
<div class="pagination">{{$interviews->links()}}</div>
@endsection
