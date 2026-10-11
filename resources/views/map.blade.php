@extends('layouts.app')
@section('title','Peta sebaran')
@section('content')
<div class="page-heading"><div><h1>Peta sebaran survey</h1><p>Buka alamat tujuan atau titik GPS kunjungan di Google Maps.</p></div><a class="btn" href="/tasks">Lihat tugas</a></div>
<div class="hint">Hijau: GPS kunjungan. Biru: koordinat alamat terdaftar. Alamat tanpa koordinat tersedia pada daftar di bawah; hasil pencarian alamat perlu dikonfirmasi dengan mahasiswa.</div>
<div class="card card-body">
    <label class="check-line"><input type="checkbox" id="show-routes">Tampilkan urutan kunjungan per surveyor</label>
    <p id="map-error" class="alert warning" role="status" hidden>Peta dasar tidak dapat dimuat. Gunakan tombol Google Maps pada daftar lokasi di bawah.</p>
    <div id="survey-map" class="map-canvas" aria-label="Sebaran koordinat survey"></div>
    <p id="map-summary" class="muted"></p>
    <noscript>Aktifkan JavaScript untuk melihat sebaran. Tombol Google Maps di bawah tetap dapat digunakan.</noscript>
</div>
<h2 style="margin:24px 0 16px">Daftar lokasi · {{count($data)}} target</h2>
<div class="task-grid">
@forelse($data as $item)
    @php
        $location = collect([$item['actual'], $item['registered']])->first(fn ($point) => isset($point['latitude'], $point['longitude']));
        $query = $location ? $location['latitude'].','.$location['longitude'] : trim(($item['address'] ?? '').' '.($item['city'] ?? ''));
    @endphp
    <article class="card task-card">
        <h3>{{$item['name']}}</h3><p>{{$item['address'] ?: 'Alamat belum diisi'}}</p><p class="muted">{{$item['city']}} · {{$item['surveyor']}}</p>
        <p class="hint">{{$location ? 'Titik koordinat tersedia' : 'Belum ada GPS · cari berdasarkan alamat'}}</p>
        <div class="task-actions">
            @if($query !== '')<a class="btn primary" href="https://www.google.com/maps/search/?api=1&amp;query={{rawurlencode($query)}}" target="_blank" rel="noopener noreferrer">Buka Google Maps</a>@else<span class="muted">Lengkapi alamat terlebih dahulu.</span>@endif
            <a class="btn" href="{{$item['url']}}">Detail</a>
        </div>
    </article>
@empty
    <p class="card empty">Belum ada lokasi penugasan.</p>
@endforelse
</div>
<script type="application/json" id="map-data">{!!json_encode($data,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)!!}</script>
@endsection
