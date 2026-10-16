<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Masuk · {{ config('app.demo_mode') ? 'Ruang latihan' : 'Aplikasi utama' }} · Survey GAKIN</title>
    <x-brand-head/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="login-shell">
    <section class="login-intro">
        <img class="login-logo" src="/brand/gakin-logo-putih.svg" alt="Survey GAKIN Poltekkes Kemenkes Manado" width="394" height="120">
        <h1>Satu kunjungan.<br>Data yang berarti.</h1>
        <p>Ruang kerja bersama untuk pelaksanaan, dokumentasi, dan verifikasi Survey Keluarga Miskin.</p>
        <div style="margin-top:50px;display:flex;gap:12px;align-items:center;color:#b4d7c8"><x-icon name="shield-check"/>Data terlindungi. Proses dapat ditelusuri.</div>
    </section>
    <section class="login-form">
        <div>
            <div class="brand" style="padding:0;margin-bottom:30px">
                <img class="brand-mark" src="/brand/gakin-mark.svg" alt="" width="44" height="44">
                <span class="brand-text"><span class="brand-eyebrow">SURVEY</span>GAKIN<span class="brand-sub">POLTEKKES MANADO</span></span>
            </div>
            <span class="badge {{ config('app.demo_mode') ? 'warning' : 'success' }} login-workspace">{{ config('app.demo_mode') ? 'Ruang latihan · Data contoh' : 'Aplikasi utama · Survey GAKIN' }}</span>
            <h2>{{ config('app.demo_mode') ? 'Ruang latihan survey' : 'Selamat datang kembali' }}</h2>
            @if(config('app.demo_mode'))
                <div class="hint login-guidance">Akun latihan: <strong>DEMO001</strong><br>Password: <strong>DEMO001</strong><br>Gunakan akun latihan pada halaman ini. Seluruh isian di ruang ini adalah contoh.</div>
            @else
                <p class="muted login-guidance">Masuk dengan email atau NIP akun petugas yang diberikan koordinator GAKIN.</p>
            @endif
            @if($errors->any())
                <div class="alert warning" role="alert" id="login-error">{{ $errors->first() }}</div>
            @endif
            <form method="post" action="{{ route('login') }}">
                @csrf
                <div class="field">
                    <label for="login">Email atau NIP</label>
                    <input id="login" name="login" value="{{ old('login') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required placeholder="Email institusi atau NIP" @if($errors->has('login')) aria-invalid="true" aria-describedby="login-error" @endif>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="login-password">
                        <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Masukkan password Anda">
                        <button class="btn login-password-toggle" type="button" data-password-toggle aria-controls="password" aria-label="Tampilkan password" aria-pressed="false" hidden>Tampilkan</button>
                    </div>
                </div>
                <button class="btn primary" type="submit">Masuk ke ruang kerja <x-icon name="arrow-right" size="17"/></button>
            </form>
            <p class="login-note">Kesulitan masuk atau belum memiliki akses? Hubungi koordinator GAKIN untuk memeriksa akses akun Anda.</p><p class="login-version">Survey GAKIN {{app(\App\Services\AppVersion::class)->label()}}</p>
            <small>© {{ date('Y') }} Poltekkes Kemenkes Manado</small>
        </div>
    </section>
</div>
</body>
</html>
