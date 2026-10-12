# Sistem Informasi Survey GAKIN

Aplikasi Laravel untuk Poltekkes Kemenkes Manado: pengelolaan mahasiswa dan pengguna, penugasan, survey lapangan, dokumentasi privat, verifikasi, PDF/Excel, serta penyusunan draft laporan perjalanan PANGI. Bahasa antarmuka Indonesia; waktu aplikasi Asia/Makassar (WITA).

## Menjalankan salinan lokal yang sudah disiapkan

Jalankan `start-local.ps1`, lalu buka **http://127.0.0.1:8000**. Script memakai PHP/MySQL Laragon yang ditemukan di komputer ini, serta menjalankan scheduler pengingat selama proses lokal aktif. MySQL khusus aplikasi berada pada port 3307 dan data tersimpan di `.runtime/mysql`; layanan hanya mengikat localhost. Sesuaikan path di script bila instalasi Laragon dipindahkan.

Kredensial awal ada di **storage/app/private/AKSES-LOKAL.txt**. Gunakan akun yang sudah ditetapkan koordinator; pengaturan akun lokal tidak disertakan dalam paket kode. File tersebut, `.env`, data MySQL, dokumen sumber, serta hasil pengujian tidak boleh dimasukkan ke Git.

Dua batch pratinjau telah disiapkan dari lampiran pengguna:

- **66 mahasiswa**, NIM unik, 12 label program studi. Satu baris memerlukan koreksi: baris 89, alamat kosong. Halaman preview menyediakan koreksi dan validasi ulang. Tidak ada alamat yang dikarang.
- **195 pegawai**, NIP unik, dibaca hanya dari sheet `LAPORAN DUK`. Sheet per unit merupakan pengelompokan ulang dan tidak diimpor berulang.

Pada instalasi baru, batch pratinjau masuk ke master setelah admin menekan **Konfirmasi import**. Salinan lokal yang sudah dikonfirmasi tidak perlu diimpor ulang. Impor pengguna dahulu, aktifkan surveyor yang diperlukan, kemudian koreksi dan konfirmasikan mahasiswa. Nama tim yang berupa jabatan atau nama pendek tidak dipetakan otomatis ke orang tertentu. Pilih akun surveyor berdasarkan nama lengkap/NIP dalam preview atau menu penugasan.

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
3. Admin memilih mahasiswa, periode, akun surveyor, dan rentang tanggal. Surveyor hanya melihat tugas sendiri. Penugasan yang sudah memiliki survey tidak dapat dipindahkan diam-diam.
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

## Jadwal pengiriman data

Menu **Pengiriman data** menyediakan jadwal harian default **16.00 WITA**. Admin dapat mengubah jam atau menjeda pengingat. Riwayat menyimpan 30 kejadian terakhir, dengan notifikasi in-app kepada admin aktif satu kali per hari. Bila jadwal baru dibuat setelah waktunya lewat, pengingat pertama berjalan esok hari. Komputer dan scheduler harus tetap menyala agar pengingat diproses.

Integrasi eksternal ditunda. Scheduler ini tidak memanggil API, tidak mengantrekan pengiriman, dan tidak mengaktifkan PANGI secara otomatis, termasuk bila konfigurasi API nantinya berubah. Status riwayat tetap **Menunggu integrasi**. Pengiriman sebenarnya memerlukan pengembangan/aktivasi terpisah.

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


## Penerapan di cPanel Jagoan Hosting

Target: **https://gakin.poltekkes-manado.ac.id**. Panduan ini memakai Terminal dan File Manager yang tersedia pada akun hosting. Belum ada perubahan pada server hosting. Paket kode berisi vendor produksi dan aset frontend yang sudah dibangun; database, `.env`, kredensial, sesi, dan bukti survey dipindahkan terpisah.

### Membuat paket di komputer pengembangan

```powershell
powershell -ExecutionPolicy Bypass -File tools/prepare-cpanel.ps1
```

Script membuat staging baru di `.runtime`, membangun frontend, memasang dependensi produksi dari `composer.lock`, lalu menghasilkan ZIP dan SHA256 di `.runtime/releases`. Script tidak mengubah database atau vendor aplikasi lokal. Paket mengekstrak folder `survey-gakin/`; log build tersimpan di folder staging. Jalankan ulang untuk setiap rilis agar perubahan header, kode, dan aset terbaru ikut masuk.

### Menyiapkan domain dan server

1. Di cPanel, pilih **PHP 8.4** untuk domain. Aktifkan ekstensi `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo_mysql`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, dan `zip`. Kebutuhan final versi dependensi mengikuti `composer.lock`; jangan memakai `--ignore-platform-reqs`.
2. Periksa PHP Terminal dengan `php -v` dan `php -m`. PHP CLI harus sama dengan PHP domain. Jika berbeda, minta/pilih path PHP 8.4 dari provider dan gunakan path absolut tersebut pada semua perintah dan cron. Path seperti `/opt/alt/php84/usr/bin/php` atau `/opt/cpanel/ea-php84/root/usr/bin/php` hanyalah contoh; periksa keberadaannya dahulu.
3. Upload ZIP ke `/home/CPANEL_USER/` melalui File Manager, lalu ekstrak. Jangan mengekstrak seluruh project di folder yang dapat diakses publik. Jika folder `survey-gakin` sudah ada, backup dan gunakan folder rilis baru atau lakukan update terkontrol; jangan menimpa `.env` dan `storage` yang aktif.
4. Di **Domains**, arahkan document root subdomain ke `/home/CPANEL_USER/survey-gakin/public`. Jika pengaturan hanya menerima path relatif, isi `survey-gakin/public`. Jika provider membatasi lokasi root, minta support menyesuaikannya. Jangan menghapus `public_html` atau memindahkan `index.php` ke root project.
5. Aktifkan sertifikat SSL/AutoSSL untuk domain, lalu **Force HTTPS Redirect**. HTTPS diperlukan untuk GPS, kamera, PWA, dan cookie sesi. Pada domain baru, tunggu DNS/SSL tersedia sebelum membuka login.
6. Buat database MySQL serta user melalui **MySQL Databases / Database Wizard**, lalu berikan hak pada database aplikasi tersebut. Gunakan nama lengkap dengan prefix cPanel. Atur `upload_max_filesize=20M`, `post_max_size=32M`, `memory_limit=256M` atau lebih jika paket mendukung, dan `max_execution_time=120` untuk cetakan gabungan. Folder `storage` serta `bootstrap/cache` harus writable oleh akun hosting; gunakan izin akun yang benar, bukan `777`.

Panduan penyedia menegaskan Laravel dilayani dari folder `public` dan membutuhkan vendor serta folder writable: [Jagoan Hosting](https://www.jagoanhosting.com/tutorial/laravel/tutorial-menjalankan-laravel-di-hosting). Pengaturan subdomain tersedia pada [cPanel Domains](https://docs.cpanel.net/cpanel/domains/domains/). Konfigurasi production mengikuti [Laravel 13 Deployment](https://laravel.com/docs/13.x/deployment).

### Konfigurasi dan pemindahan data lokal

Di Terminal (ganti `CPANEL_USER` dan path PHP sesuai akun):

```bash
cd /home/CPANEL_USER/survey-gakin
cp .env.cpanel.example .env
chmod 600 .env
```

Edit `.env` melalui File Manager: isi nama/user/password database dan pastikan `APP_URL=https://gakin.poltekkes-manado.ac.id`. Jangan masukkan file ini ke GitHub atau folder `public`. Template menetapkan `APP_ENV=production`, `APP_DEBUG=false`, `APP_DEMO=false`, sesi HTTPS, log harian, dan PANGI nonaktif.

**Untuk melanjutkan data lokal yang sudah ada:** hentikan pengisian sementara saat membuat salinan terakhir; backup database MySQL lokal dan seluruh bukti/tanda tangan yang dipakai dari `storage/app/private`, kemudian import SQL ke database hosting baru/kosong melalui phpMyAdmin atau Terminal. Salin berkas privat ke `storage/app/private` dengan susunan path yang sama. Sertakan referensi berkas impor yang diperlukan. Akun, role, penugasan, hasil survey, dan jadwal berada di database; paket kode saja tidak memuatnya. Jangan gunakan database/demo `gakin-demo.sqlite` atau menjalankan `DemoSurveySeeder` di hosting.

Pertahankan **APP_KEY yang sama** dari `.env` lokal ketika memindahkan database yang mengandung data terenkripsi. Pindahkan nilainya secara privat ke `.env` hosting; jangan cetak ke log atau kirim melalui Git. Jangan menjalankan `key:generate` untuk migrasi data tersebut. Tidak perlu memindahkan session/cache/job lokal, `AKSES-LOKAL.txt`, keluaran uji, atau folder `demo-output`. Backup SQL dan bukti harus disimpan di lokasi privat; jangan taruh ZIP/SQL di document root. Akun yang sudah dipindahkan tetap memakai hak akses serta password yang ada; tinjau password akun untuk penggunaan daring lewat menu Profil/Pengguna.

**Hanya untuk instalasi kosong tanpa pemindahan data:** isi `ADMIN_EMAIL` dan password awal yang dipilih sendiri di `.env`, lalu jalankan `php artisan key:generate --no-interaction`. Ini menghasilkan admin baru menggunakan email, bukan daftar akun lokal. Setelah instalasi berhasil dan admin bisa login, kosongkan `ADMIN_PASSWORD`/`ADMIN_EMAIL` lalu jalankan ulang `config:cache`.

### Menjalankan pemasangan

Paket ZIP sudah membawa vendor. Jika mengambil kode melalui Git, jalankan Composer dan build aset di lingkungan build terlebih dahulu; `public/build` tidak disimpan di Git. Dengan Composer pada hosting, periksa kebutuhan aktual menggunakan `composer check-platform-reqs --no-dev`.

```bash
# Setelah .env terisi dan data lokal diimpor:
php artisan gakin:deployment-check --skip-database --no-interaction
PHP_BIN=php bash tools/deploy-cpanel.sh update

# ATAU hanya pada instalasi kosong, dengan ADMIN_EMAIL/PASSWORD di .env:
# PHP_BIN=php bash tools/deploy-cpanel.sh install
```

Gunakan path PHP 8.4 yang sudah diverifikasi sebagai `PHP_BIN` bila `php` default berbeda. `update` menjalankan migrasi yang belum terpasang tanpa seeding ulang; `install` menambahkan master dan akun admin dari environment. Kedua mode tidak menjalankan `migrate:fresh`, tidak membuat data contoh, dan tidak mengganti key/password akun yang sudah ada. Backup database dan storage sebelum menjalankan script. Jika gagal setelah maintenance aktif, perbaiki penyebab dan jalankan ulang; aplikasi tetap maintenance sampai semua langkah lolos. Jangan menjalankan `up` sebelum pemeriksaan/migrasi selesai.

### Cron Jobs dan pemeriksaan setelah pemasangan

Buat satu Cron Job **setiap menit** (`* * * * *`) melalui cPanel:

```bash
/path/php84 /home/CPANEL_USER/survey-gakin/artisan schedule:run >> /home/CPANEL_USER/survey-gakin/storage/logs/scheduler.log 2>&1
```

Ganti `/path/php84` dengan path PHP CLI terverifikasi. Jangan menjalankan `schedule:work` sebagai daemon di shared hosting. Scheduler aplikasi memakai **Asia/Makassar**, sehingga pengingat tetap pukul **16.00 WITA** walaupun timezone server berbeda. Pada pemindahan data lokal, pengaturan jadwal yang sudah tersimpan ikut database; periksa menu Pengiriman data setelah import. Pada instalasi kosong, simpan jadwal 16.00 WITA melalui menu tersebut. Integrasi PANGI tetap nonaktif; cron tidak otomatis mengirim data keluar. Lihat [cPanel Cron Jobs](https://docs.cpanel.net/cpanel/advanced/cron-jobs/).

Queue untuk integrasi eksternal belum perlu dijalankan pada tahap ini. Jika nanti ada pekerjaan antrean yang diaktifkan, sesuaikan worker dengan batas paket hosting bersama administrator; jangan mengaktifkan integrasi hanya untuk mengosongkan antrean lama.

Setelah selesai, jalankan `php artisan gakin:deployment-check` dan `php artisan schedule:list`, lalu periksa `/up`, login admin/surveyor, pembatasan tugas, filter laporan/PDF, upload bukti PKH, GPS dari ponsel, serta QR pada domain baru. Pastikan `/.env`, `/composer.json`, dan `/storage/app/private/` tidak dapat diunduh. Perintah pemeriksaan tidak membuktikan konfigurasi document root, sertifikat, cron yang benar-benar berjalan, atau kuota hosting; bagian tersebut diperiksa melalui cPanel dan browser.

Simpan backup sebelum setiap update. Untuk rollback kode gunakan rilis sebelumnya dengan `.env` dan storage yang sama; rollback database harus mengikuti backup/migrasi yang relevan, bukan menghapus tabel. Cetakan lama yang pernah dibuat di localhost memiliki tautan QR localhost; cetak ulang dari domain hosting untuk tautan yang dapat digunakan penerima.
