@extends('layouts.app')
@section('title','Pengiriman data')
@section('content')
<div class="page-heading">
    <div><h1>Jadwal pengiriman data</h1><p>Pengingat harian untuk meninjau data yang akan dikirim.</p></div>
    @if(config('pangi.enabled'))<a class="btn primary" href="/pangi/trips">Ambil perjalanan saya</a>@endif
</div>
<div class="alert warning">Integrasi otomatis ditunda. Jadwal ini hanya mencatat dan mengingatkan di aplikasi; belum ada data dikirim ke PANGI.</div>
<div class="grid-two" style="margin-bottom:22px">
    <section class="card card-body">
        <div class="eyebrow">JADWAL HARIAN</div>
        <h2 style="margin:16px 0">Setiap hari pukul {{$schedule['time']}} WITA</h2>
        <p>Status: <strong>{{$schedule['enabled'] ? 'Pengingat aktif' : 'Dijeda'}}</strong></p>
        <p>Jadwal berikutnya: <strong>{{$nextRun?->format('d-m-Y H:i') ?? 'Belum dijadwalkan'}}{{$nextRun ? ' WITA' : ''}}</strong></p>
        <p class="muted">Saat jadwal tiba, admin menerima notifikasi di aplikasi. Data tetap tersimpan lokal sampai integrasi diaktifkan secara terpisah.</p>
    </section>
    @can('masters.manage')
    <section class="card card-body">
        <h2>Atur jadwal</h2>
        <form method="post" action="{{route('pangi.schedule.update')}}" style="margin-top:20px">
            @csrf
            <div class="field"><label for="delivery-time">Waktu setiap hari (WITA)</label><input id="delivery-time" name="time" type="time" value="{{old('time',$schedule['time'])}}" required></div>
            <input type="hidden" name="enabled" value="0">
            <label class="check-line"><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$schedule['enabled']))>Aktifkan pengingat harian</label>
            <button class="btn primary" style="margin-top:18px">Simpan jadwal</button>
        </form>
    </section>
    @endcan
</div>
<section class="card" style="margin-bottom:22px">
    <div class="card-body"><h2>Riwayat jadwal</h2><p class="muted">30 jadwal terakhir. Waktu ditampilkan dalam WITA.</p></div>
    <div class="table-wrap"><table><thead><tr><th>Jadwal</th><th>Status</th></tr></thead><tbody>
    @forelse($schedule['runs'] as $run)
        <tr><td>{{\Carbon\Carbon::parse($run['scheduled_at'])->timezone('Asia/Makassar')->format('d-m-Y H:i')}} WITA</td><td>{{$run['status']}}</td></tr>
    @empty
        <tr><td colspan="2" class="empty">Belum ada jadwal yang jatuh tempo. Pengingat pertama mengikuti waktu berikutnya di atas.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
@if(config('pangi.enabled'))
<div class="kpi-grid">@foreach(['connected'=>'Terhubung','ready'=>'Siap sinkron','synced'=>'Tersinkron','failed'=>'Gagal'] as $status=>$label)<div class="card kpi"><div class="kpi-top">{{$label}}</div><div class="kpi-number">{{$travels->where('status',$status)->count()}}</div></div>@endforeach</div><section class="card"><div class="table-wrap"><table><thead><tr><th>SPD</th><th>Pelaksana</th><th>Periode</th><th>Status</th><th></th></tr></thead><tbody>@forelse($travels as $t)<tr><td>{{$t->spd_number}}</td><td>{{$t->user->name}}</td><td>{{$t->starts_at->format('d M')}} – {{$t->ends_at->format('d M Y')}}</td><td><x-badge :status="$t->status"/></td><td>@if($t->user_id===auth()->id())<a class="text-link" href="/pangi/{{$t->uuid}}">Lihat perjalanan</a>@endif</td></tr>@empty<tr><td colspan="5" class="empty">Belum ada perjalanan terhubung.</td></tr>@endforelse</tbody></table></div></section>

@endif
@endsection
