# Sistem Informasi Survey GAKIN

Aplikasi Laravel untuk Poltekkes Kemenkes Manado: pengelolaan mahasiswa dan pengguna, penugasan, survey lapangan, dokumentasi privat, verifikasi, PDF/Excel, serta penyusunan draft laporan perjalanan PANGI. Bahasa antarmuka Indonesia; waktu aplikasi Asia/Makassar (WITA).

## Menjalankan salinan lokal yang sudah disiapkan

Jalankan `start-local.ps1`, lalu buka **http://127.0.0.1:8000**. Script memakai PHP/MySQL Laragon yang ditemukan di komputer ini. MySQL khusus aplikasi berada pada port 3307 dan data tersimpan di `.runtime/mysql`; layanan hanya mengikat localhost. Sesuaikan path di script bila instalasi Laragon dipindahkan.

Kredensial awal ada di **storage/app/private/AKSES-LOKAL.txt**. Password dibuat acak dan wajib diganti saat login pertama. File tersebut, `.env`, data MySQL, dokumen sumber, serta hasil pengujian tidak boleh dimasukkan ke Git.

Dua batch pratinjau telah disiapkan dari lampiran pengguna:

- **66 mahasiswa**, NIM unik, 12 label program studi. Satu baris memerlukan koreksi: baris 89, alamat kosong. Halaman preview menyediakan koreksi dan validasi ulang. Tidak ada alamat yang dikarang.
- **195 pegawai**, NIP unik, dibaca hanya dari sheet `LAPORAN DUK`. Sheet per unit merupakan pengelompokan ulang dan tidak diimpor berulang.

Batch belum masuk ke master sebelum admin menekan **Konfirmasi import**. Impor pengguna dahulu, aktifkan surveyor yang diperlukan, kemudian koreksi dan konfirmasikan mahasiswa. Nama tim yang berupa jabatan atau nama pendek tidak dipetakan otomatis ke orang tertentu. Pilih akun surveyor berdasarkan nama lengkap/NIP dalam preview atau menu penugasan.

## Teknologi dan requirements

- Laravel 13, PHP **8.3.33+** atau 8.4, Composer 2.
- MySQL 8 / MariaDB yang didukung Laravel; SQLite dipakai hanya pada pengujian terisolasi.
- Node.js 22.12+ dan npm; Vite, Tailwind 4, Livewire 4 dengan Alpine dibundel lokal.
- PHP extensions: PDO MySQL/SQLite, mbstring, openssl, tokenizer, XML/DOM, ctype, fileinfo, curl, zip, GD, intl.
- Spatie Permission, Sanctum, Laravel Excel, DomPDF, Endroid QR, Leaflet/OpenStreetMap, Signature Pad, IndexedDB (`idb`).

Versi dependensi terkunci dalam `composer.lock` dan `package-lock.json`; Composer platform diatur ke PHP 8.3.33. Laravel 13 memerlukan PHP 8.3 minimum menurut [catatan rilis resmi](https://github.com/laravel/docs/blob/13.x/releases.md). Integrasi Livewire mengikuti [dokumentasi resmi](https://livewire.laravel.com/docs/4.x/installation).

## Instalasi baru

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Buat database MySQL dan akun aplikasi terpisah, lalu isi `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Isi `ADMIN_EMAIL` dan `ADMIN_PASSWORD` dengan kredensial awal pribadi, bukan nilai contoh yang diketahui publik. Seeder hanya membuat admin bila keduanya tersedia dan tidak mengubah password akun yang sudah ada.

```bash
php artisan migrate --seed
npm ci
npm run build
php artisan storage:link
php artisan serve
```

Seeder mengisi lima role, permissions, program studi dari label lampiran, satu versi berisi 10 kriteria, periode 2026, dan pengaturan dokumen. Seeder **tidak** membuat survey palsu atau menetapkan rekomendasi mahasiswa.

`storage:link` hanya untuk aset publik Laravel. Bukti survey dan tanda tangan tetap pada disk `local`, di `storage/app/private`, dan tidak disajikan melalui symlink publik. Route media memeriksa sesi dan policy survey. Halaman publik QR tidak menampilkan alamat, GPS, checklist, pendapatan atau foto.

## Penggunaan

1. Admin mengimpor Excel dan meninjau validasi serta duplikasi. Mapping kolom khusus menerima objek JSON dengan indeks berbasis 0; format kedua lampiran didukung secara default. Nomor telepon dinormalisasi `62`, NIP/NIM disimpan sebagai teks. Label tanggal rentang tetap menjadi catatan sumber; admin memilih tanggal pasti.
2. Akun hasil impor nonaktif dengan password acak yang tidak dibagikan. Admin menetapkan password awal saat aktivasi, memperbaiki email yang kosong, dan memilih peran. Email pengganti berakhiran `@pending.invalid` tidak dianggap alamat nyata.
3. Admin memilih mahasiswa, periode, surveyor, tim opsional, dan tanggal. Surveyor hanya melihat tugas sendiri. Penugasan yang sudah memiliki survey tidak dapat dipindahkan diam-diam.
4. Surveyor mengisi wizard: identitas, orang tua, tempat tinggal, 10 kriteria, bukti, GPS, kesimpulan, tanda tangan, review, submit. Tanda tangan memerlukan isian/bukti wajib yang lengkap.
5. Submit memvalidasi mandatory, menghitung hash dokumen, mengunci data, dan mengirim notifikasi verifikator. Verifikator tidak boleh memverifikasi survey sendiri. Revisi wajib beralasan, membatalkan QR lama dan tanda tangan, kemudian membuka isian kembali. Persetujuan/penolakan tercatat di riwayat dan audit.
6. PDF A4 mengikuti struktur contoh: identitas dokumen, nama/prodi, tabel 10 kriteria, rekomendasi, dua tanda tangan, QR, lampiran foto. Tahun mengikuti waktu survey; tanggal penetapan/revisi/edisi dapat diatur. QR menyatakan integritas dokumen tersimpan; status proses verifikasi ditampilkan terpisah.

Formulir sumber menggunakan istilah **900 kwh** dan metadata penetapan **2 Februari 2024**. Keduanya dipertahankan sebagai data referensi; koreksi satuan atau ketentuan resmi dilakukan melalui versi form baru setelah ditetapkan institusi. Ambang jumlah kriteria tidak tersedia dalam sumber, sehingga defaultnya kosong dan menghasilkan saran **perlu verifikasi**, kecuali aturan PKH yang diaktifkan. Saran sistem selalu memerlukan keputusan manusia.

## Hak akses

| Role | Hak utama |
|---|---|
| super-admin | Semua permission, pengguna, master, audit, integrasi |
| koordinator | Import, mahasiswa, penugasan, monitoring, verifikasi, laporan |
| surveyor | Tugas sendiri, draft, bukti, tanda tangan, submit, PANGI sendiri |
| verifikator | Review seluruh survey, approve/reject/revisi, laporan |
| pimpinan | Monitoring dan laporan; tidak diberi hak verifikasi secara default |

Spatie menyediakan permission granular; definisinya berada pada `DatabaseSeeder`. Policy tetap membatasi perubahan survey kepada pelaksana pemilik survey yang belum terkunci, termasuk ketika akun memiliki akses monitoring seluruh data.

## PWA, offline, dan konflik

PWA membutuhkan HTTPS pada server produksi; localhost dapat dipakai untuk pengujian. Cache halaman privat berlaku paling lama 12 jam sejak sesi online terakhir dan dihapus ketika logout, berganti akun, atau server menolak akses. Buka tugas/survey saat online terlebih dahulu agar HTML dan aset tersedia di perangkat. Jawaban, catatan, data orang tua, GPS dan antrean foto disimpan di IndexedDB per UUID pengguna. Foto dikompresi sebelum antre dan diberi watermark di server sesuai snapshot pengaturan.

Autosave memiliki nomor revisi optimistis: isian dari tab/perangkat lama tidak menimpa versi baru. Saat konflik, tombol perbandingan menampilkan isian perangkat dan server; pengguna memilih versi secara eksplisit. Salinan sebelum penyelesaian konflik disimpan sebagai backup lokal. Perangkat yang penuh atau menolak IndexedDB menampilkan pesan kegagalan; jangan tutup halaman sebelum data berhasil disimpan.

Tanda tangan dan finalisasi dilakukan saat online agar divalidasi terhadap isi dan bukti server terakhir. Semua perubahan isi/bukti membatalkan tanda tangan sebelumnya. Peta tile/navigasi eksternal memerlukan internet. Tidak ada background tracking GPS. Logout membersihkan draft/cache lokal; sinkronkan pekerjaan terlebih dahulu. Cache lokal mengandung data tugas, sehingga perangkat lapangan harus dilindungi akun/kunci layar dan tidak dipakai bersama tanpa logout.

## Queue, scheduler, notifikasi

```bash
php artisan queue:work --tries=3 --timeout=40
php artisan schedule:work
```

Produksi: supervisor/systemd untuk queue, dan cron `* * * * * php /path/artisan schedule:run` untuk scheduler. `gakin:remind` memberi notifikasi in-app sehari sebelum jadwal pukul 16.00 WITA. `gakin:prepare-reports` mengecek setiap 15 menit apakah seluruh target perjalanan selesai dan menyiapkan draft. Tidak ada email/WhatsApp otomatis yang dikirim ke kontak sumber; tombol WhatsApp hanya membuka percakapan untuk pengguna.

Upload foto HTTP dikompresi di browser dan dinormalisasi server; antrean offline menyimpan blob sampai pengiriman berhasil. Queue server digunakan untuk sinkronisasi PANGI dan retry. Notifikasi tersimpan di database.

## Konfigurasi PANGI

```dotenv
PANGI_ENABLED=false
PANGI_BASE_URL=https://pangi.poltekkes-manado.ac.id
PANGI_TOKEN=
PANGI_RETRY_LIMIT=3
PANGI_AUTO_PREPARE=true
```

Kontrak detail: [docs/PANGI-API.md](docs/PANGI-API.md). Endpoint partner belum diverifikasi pada server nyata. Pengelola PANGI perlu menyetujui kontrak dan memberikan token dengan scope minimum. Admin memasukkan UUID pengguna PANGI di menu Pengguna, lalu surveyor menarik perjalanan milik dirinya. Tidak ada pencocokan otomatis hanya berdasarkan nama.

Draft berisi fakta kunjungan umum dan disusun per pelaksana serta tanggal. Pengguna meninjau, dapat menyunting tindak lanjut, lalu mengonfirmasi Kirim. Server mengirim **draft**, tidak approve atau menyimulasikan tanda tangan pimpinan. Data ekonomi rinci dan media tidak masuk payload.

Status `failed`: periksa worker queue, koneksi HTTPS, mapping UUID, scope/masa berlaku token, ownership, tanggal dan status perjalanan. Periksa `integration_logs` yang telah disanitasi. Perbaiki penyebab lalu gunakan tombol Coba sinkron lagi. Status `locked` berarti perlu pemeriksaan di PANGI, bukan bypass dari GAKIN. Idempotency key stabil untuk retry payload yang sama; remote harus menerapkan upsert dan constraint unik sesuai kontrak.

Sanctum juga tersedia pada `/api/me` dengan scope `gakin.read-profile`; endpoint ini hanya mengembalikan UUID dan nama, bukan data keluarga. Token inbound harus dibuat administrator secara terkontrol; tidak ada pendaftaran token publik.

## Struktur implementasi

- `app/Services`: transaksi survey, hash, rekomendasi, import, query terotorisasi, klien PANGI, report builder, audit.
- `app/Http/Controllers`, `Requests`, `Middleware`: transport HTTP, validasi, sesi aktif.
- `app/Policies`, `Enums`, `Events`, `Listeners`, `Notifications`, `Observers`, `Jobs`: otorisasi, status, notifikasi, audit, queue.
- `app/Livewire`: polling notifikasi; `resources/views`: dashboard, wizard Blade/Alpine, master, preview dan cetak.
- `resources/js`: draft IndexedDB, foto antre, tanda tangan, GPS, peta. `public/sw.js`: caching offline terbatas.
- `database/migrations`, `seeders`, `factories`: schema, master awal dan data uji sintetis.
- `tests/Feature`: autentikasi, role, ownership, draft, GPS, signature, submit/lock/revisi, file, QR, PDF/Excel, import, penugasan, PANGI.

## Pengujian

```bash
php artisan test
npm run build
npm run test:offline
php artisan view:cache
```

Test PHP memakai SQLite in-memory dan file storage palsu; tidak menghapus database MySQL aplikasi. Pengujian browser tambahan sudah dilakukan pada database QA terpisah: login, dashboard, wizard, autosave, offline reload, sinkronisasi ulang, viewport ponsel, unggah foto, pengambilan GPS, tanda tangan kedua pihak, dan finalisasi. `tools/browser-check.mjs` adalah helper QA lokal yang memerlukan Playwright dan server/data QA; set `PLAYWRIGHT_MODULE` ke path package.json Playwright bila tidak tersedia di node_modules. Mode `QA_FULL_FLOW=1` memerlukan survey QA yang masih draft dan foto sintetis `.runtime/fixture.jpg`; mode ini memfinalisasi survey QA.

## Deployment produksi

Gunakan web root **public/**, HTTPS, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `APP_URL` domain yang benar, akun MySQL berhak minimum, storage privat, backup terenkripsi untuk database/bukti dan pemantauan queue. Jangan mengekspos `.env`, `.runtime`, `storage/app/private`, atau direktori project secara langsung. Backup dan retensi harus mencakup semua versi bukti/tanda tangan yang disimpan untuk audit. Atur batas upload PHP/web server setidaknya 10 MB per foto dan 20 MB per Excel.

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Salinan lokal dan kredensial awal adalah untuk pengembangan. Integrasi PANGI nyata, pengujian penerimaan institusi, kebijakan ambang rekomendasi, serta keputusan perbaikan data sumber tetap perlu diselesaikan sebelum penggunaan produksi. Fitur AI perapian bahasa yang bersifat opsional tidak diaktifkan; seluruh narasi otomatis saat ini berasal dari data terstruktur.
