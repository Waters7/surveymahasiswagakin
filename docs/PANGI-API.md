# Kontrak integrasi PANGI

Jalur ini disediakan PANGI di `/api/integrations/gakin` (branch `claude/integrasi-gakin` di repositori PANGI) dan dipakai klien `App\Services\PangiClient`. Kedua sisi sudah diuji dengan tes otomatis; uji di server produksi PANGI belum dilakukan.

## Autentikasi dan kepemilikan

Base URL: `https://pangi.poltekkes-manado.ac.id/api/integrations/gakin`.

Setiap permintaan membawa bearer token server dan header `X-Pangi-User` berisi **NIP** akun GAKIN. Token sama dipasang di `.env` kedua aplikasi (`PANGI_TOKEN` di GAKIN, `PANGI_GAKIN_TOKEN` di PANGI); token kosong di PANGI membuat jalur tertutup (503). PANGI mencocokkan NIP dengan tepat satu pegawai, lalu mengecek kepemilikan usulan di setiap endpoint. Nama tidak dipakai sebagai identitas, jadi NIP di GAKIN harus sama persis dengan NIP pegawai di PANGI.

GAKIN wajib HTTPS. HTTP hanya diterima untuk `127.0.0.1`/`localhost` saat `APP_ENV=local`. Batas laju 60 permintaan per menit; token dan payload tidak dicatat ke log.

## Endpoint

| Metode | Path | Respons / perilaku |
|---|---|---|
| GET | `/me/trips` | `{"data":[Trip,...]}` usulan berstatus disetujui milik pelaksana |
| GET | `/trips/{id}` | `{"data":Trip}`; 403 bila bukan milik pelaksana, 404 bila tidak ada |
| GET | `/trips/{id}/report-status` | `{"data":{"locked":false,"status":"belum_ada"}}` |
| PUT | `/trips/{id}/report-draft` | Upsert draft Laporan Perjadin; satu-satunya jalur tulis API PANGI |

`{id}` adalah id usulan PANGI. Setiap peserta rombongan memiliki usulan sendiri, sehingga laporan selalu per pelaksana.

`Trip`:

```json
{
  "uuid": "1287",
  "user_uuid": "199310182025061003",
  "spd_number": "KU.02.05/F.XXX.8/123/2026",
  "proposal_uuid": "USL-2026-123",
  "assignment_number": "TGS-2026-123",
  "destination": "Kabupaten Minahasa",
  "starts_at": "2026-10-12",
  "ends_at": "2026-10-16",
  "status": "approved"
}
```

## Payload draft

Payload dibentuk dari survey terkunci milik pelaksana pada perjalanan tersebut. Tanggal/jam memakai Asia/Makassar. Lokasi hanya kabupaten/kota. Hanya tindak lanjut yang dapat disunting di preview; fakta lain dibentuk ulang dari database.

```json
{
  "external_source": "survey-gakin",
  "external_reference": "SG-UUID-TRAVEL-LINK",
  "travel_uuid": "1287",
  "daily_activities": [{
    "date": "2026-10-15",
    "start_time": "08:20",
    "end_time": "09:05",
    "description": "Melaksanakan Survey GAKIN terhadap 1 mahasiswa: Nama Contoh.",
    "locations": ["Kabupaten Minahasa"]
  }],
  "target_count": 1,
  "completed_count": 1,
  "result_summary": "Survey GAKIN telah diselesaikan terhadap 1 dari 1 target penugasan.",
  "conclusion": "Seluruh target survey telah selesai.",
  "follow_up": "Verifikasi dan rekapitulasi hasil Survey GAKIN."
}
```

Kunjungan yang dicatat **Gagal survey** di kartu tugas ikut masuk ke kegiatan harian pada tanggal kejadiannya, misalnya `Survey belum berhasil dilaksanakan terhadap 1 mahasiswa: Nama Contoh (tidak di rumah).`, dan jumlahnya disebut di `result_summary`. Alasan yang dikirim hanya label (tidak dapat dihubungi, tidak di rumah) atau keterangan surveyor untuk alasan lainnya. Kegagalan di luar tanggal perjalanan atau yang disusul survey berhasil pada hari yang sama tidak dicantumkan. Gambar bukti gagal survey tetap di GAKIN.

PANGI hanya menyimpan kolom di atas dan membuang properti lain. GAKIN tidak mengirim penghasilan, PKH, kondisi kesehatan, rekomendasi ekonomi, checklist, jawaban kuesioner, foto, tanda tangan, alamat rumah, nomor HP, atau koordinat GPS.

Di PANGI, isian dipetakan ke Laporan Perjadin usulan tersebut:

- `daily_activities` → Laporan Kegiatan bertanda sumber GAKIN (uraian ditambah jam kegiatan). Kegiatan yang diketik pelaksana di PANGI tidak disentuh.
- `result_summary` → Hasil, `conclusion` → Kesimpulan. Hanya ditulis bila kolom masih kosong atau masih sama dengan kiriman GAKIN sebelumnya, sehingga suntingan pelaksana di PANGI tidak tertimpa.
- `follow_up` → Tindak Lanjut berstatus rencana, bertanda sumber GAKIN.

Tanggal kegiatan harus di antara tanggal mulai dan selesai usulan (422 bila tidak).

## Idempotensi dan penguncian

Setiap PUT membawa `Idempotency-Key: GAKIN-{travel-link-uuid}-{sha256-payload}`. Kunci tetap sama untuk retry payload identik dan berubah bila isi berubah. PANGI menyimpan kunci terakhir per usulan; kunci yang sama mengembalikan hasil sebelumnya tanpa menulis ulang. Pemeriksaan kunci dan status laporan dilakukan dalam transaksi dengan penguncian baris.

Respons: 201 saat laporan baru dibuat, 200 saat diperbarui, dengan `{"data":{"uuid":"REPORT-ID","status":"draft","locked":false}}`. 423 berarti laporan sudah dikirim, dikonfirmasi, atau perjalanan selesai; GAKIN menghentikan retry otomatis. 401/403 untuk token/kepemilikan; 422 untuk validasi atau usulan yang belum disetujui; 429/5xx/timeout dapat dicoba lagi dengan kunci sama.

## Batas kewenangan

Tidak ada endpoint approve, submit laporan final, tanda tangan pimpinan, atau QR persetujuan PANGI. Hasil sinkronisasi tetap draft. Pelaksana membuka PANGI untuk memeriksa dan mengirim laporan lewat workflow resmi. Auto-prepare hanya menyiapkan draft; pengiriman memerlukan centang konfirmasi dan tombol Kirim oleh pemilik laporan.

## Uji penerimaan bersama pengelola PANGI

Uji token salah dan kosong; NIP yang tidak cocok; perjalanan orang lain; tanggal di luar rentang; upsert pertama; retry dengan kunci sama; perubahan draft; laporan yang sudah dikirim; serta pemeriksaan bahwa field sensitif tidak terkirim. Gunakan data sintetis sebelum mengaktifkan `PANGI_ENABLED=true` di produksi.
