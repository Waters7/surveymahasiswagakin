@extends('layouts.app')
@section('title', $user ? 'Edit pengguna' : 'Tambah pengguna')
@section('content')
@php
    $roleLabel = fn (string $role): string => \Illuminate\Support\Str::of($role)->replace('-', ' ')->title();
    $isSelf = $user?->id === auth()->id();
    $currentRole = old('role', $user?->roles->first()?->name ?? ($roles->contains('surveyor') ? 'surveyor' : $roles->first()));
    $active = (string) old('active', $user ? (int) $user->active : 1);
    $pendingEmail = $user && str_ends_with((string) $user->email, '@pending.invalid');
    $identity = [
        'name' => ['Nama lengkap', 'text', true, 'name'], 'email' => ['Email', 'email', true, 'email'],
        'nip' => ['NIP (18 digit)', 'text', false, 'off'], 'phone' => ['Nomor HP', 'tel', false, 'tel'],
        'position' => ['Jabatan', 'text', false, 'organization-title'], 'unit' => ['Unit kerja', 'text', false, 'organization'],
    ];
@endphp
<div class="page-heading" style="flex-wrap:wrap">
    <div>
        <a class="text-link" href="{{route('users.index')}}"><x-icon name="arrow-left" size="14"/>Daftar pengguna</a>
        <h1 style="margin-top:12px">{{$user ? $user->name : 'Tambah pengguna'}}</h1>
        <p>{{$user ? 'Perbarui identitas, peran, status akses, atau reset password.' : 'Buat akun baru. Pengguna wajib mengganti password awal saat login pertama.'}}</p>
    </div>
    @if($user)
        <div class="user-meta">
            <span class="badge {{$user->active ? 'success' : ''}}">{{$user->active ? 'Aktif' : 'Nonaktif'}}</span>
            @if($user->active && $user->must_change_password)<span class="badge warning">Wajib ganti password</span>@endif
            <small>Terakhir masuk: {{$lastLoginAt ? \Illuminate\Support\Carbon::parse($lastLoginAt)->timezone('Asia/Makassar')->format('d M Y H:i').' WITA' : 'belum pernah'}}</small>
        </div>
    @endif
</div>
<form class="user-form" method="post" action="{{$user ? route('users.update', $user) : route('users.store')}}">
    @csrf
    <div class="user-form-grid">
        <section class="card card-body">
            <h2>Identitas</h2>
            <p class="muted">Email atau NIP dipakai untuk masuk ke aplikasi.</p>
            <div class="form-grid" style="margin-top:18px">
                @foreach($identity as $field => [$label, $type, $required, $autocomplete])
                    <div class="field">
                        <label for="user-{{$field}}">{{$label}}@if($required) <span aria-hidden="true">*</span>@endif</label>
                        <input id="user-{{$field}}" name="{{$field}}" type="{{$type}}" value="{{old($field, $user?->$field)}}" autocomplete="{{$autocomplete}}" @required($required) @if($field === 'nip') inputmode="numeric" maxlength="18" @endif @error($field) aria-invalid="true" aria-describedby="user-{{$field}}-error" @enderror>
                        @error($field)<small class="field-error" id="user-{{$field}}-error">{{$message}}</small>@enderror
                    </div>
                @endforeach
            </div>
            @if($pendingEmail)<div class="hint" style="margin:0">Email ini masih pengganti dari impor (@pending.invalid). Ganti dengan email asli pengguna.</div>@endif
        </section>
        <section class="card card-body">
            <h2>Akses</h2>
            <p class="muted">Peran menentukan menu dan kewenangan pengguna.</p>
            <fieldset class="field" style="margin-top:18px">
                <legend>Peran</legend>
                <div class="choice-row">
                    @foreach($roles as $role)
                        <label class="choice"><input type="radio" name="role" value="{{$role}}" @checked($currentRole === $role) @disabled($isSelf && $role !== 'super-admin') required>{{$roleLabel($role)}}</label>
                    @endforeach
                </div>
                @error('role')<small class="field-error">{{$message}}</small>@enderror
            </fieldset>
            <fieldset class="field">
                <legend>Status akses</legend>
                <div class="choice-row">
                    <label class="choice"><input type="radio" name="active" value="1" @checked($active === '1')>Aktif</label>
                    <label class="choice"><input type="radio" name="active" value="0" @checked($active === '0') @disabled($isSelf)>Nonaktif</label>
                </div>
                <small class="muted">Akun nonaktif tidak dapat masuk. Mengaktifkan akun yang nonaktif memerlukan password awal.</small>
            </fieldset>
            @if($isSelf)<div class="hint" style="margin:0">Ini akun Anda sendiri, sehingga peran dan status aksesnya tidak dapat diturunkan.</div>@endif
        </section>
        <section class="card card-body">
            <h2>{{$user ? 'Reset password' : 'Password awal'}}</h2>
            <p class="muted">Minimal 12 karakter. Pengguna wajib menggantinya saat login berikutnya.</p>
            <div class="field" style="margin-top:18px">
                <label for="user-password">{{$user ? 'Password baru (opsional)' : 'Password awal'}}@if(!$user) <span aria-hidden="true">*</span>@endif</label>
                <div class="login-password">
                    <input id="user-password" name="password" type="password" minlength="12" maxlength="128" autocomplete="new-password" @required(!$user) placeholder="{{$user ? 'Kosongkan untuk mempertahankan password' : 'Minimal 12 karakter'}}" @error('password') aria-invalid="true" aria-describedby="user-password-error" @enderror>
                    <button class="btn login-password-toggle" type="button" data-password-toggle aria-controls="user-password" aria-label="Tampilkan password" aria-pressed="false" hidden>Tampilkan</button>
                </div>
                @error('password')<small class="field-error" id="user-password-error">{{$message}}</small>@enderror
            </div>
        </section>
        <section class="card card-body">
            <h2>Integrasi PANGI</h2>
            <p class="muted">Opsional. Isi UUID pengguna dari administrator PANGI untuk menghubungkan laporan perjalanan dinas.</p>
            <div class="field" style="margin-top:18px">
                <label for="user-external">UUID pengguna PANGI</label>
                <input id="user-external" name="external_user_id" value="{{old('external_user_id', $user?->integration?->external_user_id)}}" autocomplete="off" placeholder="Contoh: 0f8fad5b-d9cb-469f-a165-70867728950e" @error('external_user_id') aria-invalid="true" @enderror>
                @error('external_user_id')<small class="field-error">{{$message}}</small>@enderror
            </div>
        </section>
    </div>
    <div class="sticky-actions"><span class="muted">Kolom bertanda * wajib diisi.</span><div class="heading-actions"><a class="btn" href="{{route('users.index')}}">Batal</a><button class="btn primary"><x-icon name="check-circle-2" size="16"/>{{$user ? 'Simpan perubahan' : 'Buat pengguna'}}</button></div></div>
</form>
@endsection
