@extends('layouts.app')
@section('title','Peta sebaran')
@section('content')
<div class="page-heading"><div><h1>Peta sebaran survey</h1><p>Lihat lokasi penugasan dan kunjungan melalui OpenStreetMap.</p></div><a class="btn" href="/tasks">Lihat tugas</a></div>
<div class="hint">Hijau: GPS kunjungan. Biru: koordinat alamat terdaftar. Oranye berangka: titik perkiraan dari nama desa/kecamatan/kabupaten pada alamat, berisi jumlah target. Titik perkiraan bukan lokasi rumah; konfirmasi dengan mahasiswa.</div>
<div class="card card-body map-panel">
    <div class="map-toolbar"><button class="btn" type="button" id="map-fit">Tampilkan semua titik</button><a class="text-link" href="#map-locations">Daftar lokasi</a><span class="muted">Leaflet + OpenStreetMap</span></div>
    <label class="check-line"><input type="checkbox" id="show-routes">Hubungkan urutan kunjungan (bukan rute jalan)</label>
    <div id="map-error" class="alert warning" role="status" hidden><span>Peta belum dapat dimuat lengkap. Periksa koneksi atau buka lokasi melalui OpenStreetMap di daftar bawah.</span><button class="btn" type="button" id="map-retry">Coba lagi</button></div>
    <div id="survey-map" class="map-canvas" aria-label="Sebaran koordinat survey"></div>
    <p id="map-summary" class="muted" aria-live="polite">Memuat lokasi…</p>
    <noscript>Aktifkan JavaScript untuk melihat sebaran. Tautan OpenStreetMap di bawah tetap dapat digunakan.</noscript>
</div>
<h2 id="map-locations" style="margin:24px 0 16px">Daftar lokasi · {{count($data)}} target</h2>
<div class="task-grid">
@forelse($data as $item)
    @php
        $location = collect([$item['actual'], $item['registered']])->first(fn ($point) => isset($point['latitude'], $point['longitude']));
        $approximate = $location ? null : $item['approximate'];
        $query = trim(($item['address'] ?? '').' '.($item['city'] ?? ''));
        $mapUrl = $location ? 'https://www.openstreetmap.org/?mlat='.$location['latitude'].'&mlon='.$location['longitude'].'#map=17/'.$location['latitude'].'/'.$location['longitude'] : 'https://www.openstreetmap.org/search?query='.rawurlencode($query);
    @endphp
    <article class="card task-card map-location-card">
        <h3>{{$item['name']}}</h3><p>{{$item['address'] ?: 'Alamat belum diisi'}}</p><p class="muted">{{$item['city']}} · {{$item['surveyor']}}</p>
        <p class="hint">@if($location) Titik koordinat tersedia @elseif($approximate) Titik perkiraan tingkat {{$approximate['precision']}}: {{$approximate['label']}} @else Belum ada GPS · cari berdasarkan alamat @endif</p>
        <div class="task-actions">
            @if($location || $approximate)<button class="btn primary" type="button" data-map-focus="{{$loop->index}}" hidden>{{$location ? 'Lihat titik' : 'Lihat titik perkiraan'}}</button>@endif
            @if($location || $query !== '')<a class="btn" href="{{$mapUrl}}" target="_blank" rel="noopener noreferrer">{{$location ? 'Buka OpenStreetMap' : 'Cari alamat di OpenStreetMap'}}</a>@else<span class="muted">Lengkapi alamat terlebih dahulu.</span>@endif
            <a class="btn" href="{{$item['url']}}">Detail</a>
        </div>
    </article>
@empty
    <p class="card empty">Belum ada lokasi penugasan.</p>
@endforelse
</div>
<script type="application/json" id="map-data">{!!json_encode($data,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)!!}</script>
@endsection
