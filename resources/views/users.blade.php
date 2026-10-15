@extends('layouts.app')
@section('title', 'Pengguna')
@section('content')
@php
    $roleLabel = fn (string $role): string => \Illuminate\Support\Str::of($role)->replace('-', ' ')->title();
    $statusOptions = ['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'ganti-password' => 'Wajib ganti password', 'email' => 'Email perlu diperbaiki'];
    $stats = [['', 'Total pengguna', $summary->total], ['aktif', 'Aktif', $summary->aktif], ['nonaktif', 'Nonaktif', $summary->total - $summary->aktif], ['ganti-password', 'Wajib ganti password', $summary->ganti_password], ['email', 'Email perlu diperbaiki', $summary->email]];
@endphp
<div class="page-heading" style="flex-wrap:wrap">
    <div><h1>Pengguna</h1><p>Kelola akun, peran, status akses, dan password awal pengguna aplikasi.</p></div>
    <div class="heading-actions"><a class="btn" href="/roles"><x-icon name="sliders-horizontal" size="16"/>Role & permission</a><a class="btn primary" href="{{route('users.create')}}"><x-icon name="user-plus" size="16"/>Tambah pengguna</a></div>
</div>
<nav class="user-summary" aria-label="Ringkasan status pengguna">
    @foreach($stats as [$status, $label, $count])
        <a class="card user-stat {{($filters['status'] ?? '') === $status ? 'active' : ''}}" href="{{route('users.index', array_filter(['status' => $status] + $filters))}}" @if(($filters['status'] ?? '') === $status) aria-current="true" @endif><span>{{$label}}</span><strong>{{$count}}</strong></a>
    @endforeach
</nav>
<form class="filter-bar" method="get" action="{{route('users.index')}}">
    <div class="field"><label for="user-search">Cari</label><input id="user-search" name="q" value="{{$filters['q'] ?? ''}}" placeholder="Nama, NIP, atau email"></div>
    <div class="field"><label for="user-role">Peran</label><select id="user-role" name="role"><option value="">Semua peran</option>@foreach($roles as $role)<option value="{{$role}}" @selected(($filters['role'] ?? '') === $role)>{{$roleLabel($role)}}</option>@endforeach</select></div>
    <div class="field"><label for="user-status">Status</label><select id="user-status" name="status"><option value="">Semua status</option>@foreach($statusOptions as $value => $label)<option value="{{$value}}" @selected(($filters['status'] ?? '') === $value)>{{$label}}</option>@endforeach</select></div>
    <button class="btn primary">Terapkan</button><a class="btn" href="{{route('users.index')}}">Reset</a>
</form>
<section class="card">
    <div class="card-header"><div><h2>Daftar pengguna</h2><p>{{$users->total()}} pengguna sesuai filter. Akun impor memakai NIP sebagai identitas masuk.</p></div></div>
    <div class="table-wrap"><table class="user-table"><thead><tr><th>Pengguna</th><th>Peran</th><th>Status</th><th>Terakhir masuk</th><th><span class="sr-only">Aksi</span></th></tr></thead><tbody>
    @forelse($users as $user)
        @php($pendingEmail = str_ends_with((string) $user->email, '@pending.invalid'))
        <tr>
            <td data-label="Pengguna"><div class="student-name"><span class="avatar">{{mb_substr($user->name, 0, 1)}}</span><div><strong>{{$user->name}}</strong><small>{{$pendingEmail ? 'Email belum diisi' : $user->email}}@if($user->nip) · NIP {{$user->nip}}@endif</small></div></div></td>
            <td data-label="Peran">@forelse($user->roles as $role)<span class="badge">{{$roleLabel($role->name)}}</span>@empty<span class="muted">Belum ada peran</span>@endforelse</td>
            <td data-label="Status"><span class="badge {{$user->active ? 'success' : ''}}">{{$user->active ? 'Aktif' : 'Nonaktif'}}</span>@if($user->active && $user->must_change_password)<small>Wajib ganti password</small>@endif @if($pendingEmail)<small class="text-warning">Email perlu diperbaiki</small>@endif </td>
            <td data-label="Terakhir masuk">@if($user->last_login_at){{$user->last_login_at->timezone('Asia/Makassar')->format('d M Y H:i')}}<small>WITA</small>@else<span class="muted">Belum pernah</span>@endif</td>
            <td class="row-actions"><a class="btn" href="{{route('users.edit', $user)}}" aria-label="Edit {{$user->name}}"><x-icon name="pencil" size="15"/>Edit</a></td>
        </tr>
    @empty
        <tr><td colspan="5" class="empty">Tidak ada pengguna sesuai filter.</td></tr>
    @endforelse
    </tbody></table></div>
    <div class="pagination">{{$users->links()}}</div>
</section>
@endsection
