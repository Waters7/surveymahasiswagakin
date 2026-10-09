# Kontrak integrasi PANGI

Kontrak ini adalah rancangan endpoint yang perlu disediakan atau disesuaikan di server PANGI. Keberadaan endpoint tersebut belum dikonfirmasi dan belum diuji terhadap server produksi. Aplikasi GAKIN sudah memiliki klien, pembangun draft, antrean pengiriman, preview, log, dan pengujian HTTP simulasi.

## Autentikasi dan kepemilikan

Base URL: `https://pangi.poltekkes-manado.ac.id/api/integrations/gakin`.

Semua endpoint menggunakan HTTPS, bearer token server dengan scope `gakin.read-trips` dan/atau `gakin.write-report-draft`, serta header `X-Pangi-User` berisi UUID pengguna yang telah dipetakan admin. Header ini tidak boleh dipercaya tanpa pemeriksaan token. PANGI harus mengizinkan token integrasi mewakili pengguna tersebut dan mengecek kepemilikan perjalanan di setiap endpoint. NIP/email hanya membantu pencocokan identitas; nama tidak dipakai sebagai identifier.

Rate limit yang disarankan: 60 permintaan per menit per token; respons 429 disertai `Retry-After`. Jangan menulis bearer token, seluruh payload, rincian ekonomi keluarga, atau foto ke log. Token dipasang melalui `.env`, bukan formulir web. Rotasi token dilakukan oleh pengelola kedua sistem.

## Endpoint

| Metode | Path | Respons / perilaku |
|---|---|---|
| GET | `/me/trips` | `{"data":[Trip,...]}` hanya perjalanan pelaksana terautentikasi |
| GET | `/trips/{uuid}` | `{"data":Trip}`; 403 jika bukan milik pelaksana |
| GET | `/trips/{uuid}/report-status` | `{"data":{"locked":false,"status":"draft"}}` |
| POST | `/trips/{uuid}/report-draft` | Alternatif create; idempotensi tetap wajib |
| PUT | `/trips/{uuid}/report-draft` | Upsert draft; metode yang dipakai klien GAKIN |

`Trip` minimal:

```json
{
  "uuid": "22222222-2222-4222-8222-222222222222",
  "user_uuid": "11111111-1111-4111-8111-111111111111",
  "spd_number": "090/CONTOH/2026",
  "proposal_uuid": null,
  "assignment_number": "ST/CONTOH/2026",
  "destination": "Kabupaten Minahasa",
  "starts_at": "2026-10-12",
  "ends_at": "2026-10-16",
  "status": "approved"
}
```

Status perjalanan yang dapat dipakai klien: `active` atau `approved`. Penugasan harus milik pelaksana yang sama, terjadwal di dalam tanggal perjalanan, serta belum dimulai. Satu SPD dapat dimiliki beberapa pelaksana, tetapi draft diidentifikasi per pelaksana, bukan SPD saja.

## Payload draft

Payload dibentuk dari survey terkunci milik pelaksana pada perjalanan tersebut. Tanggal/jam menggunakan Asia/Makassar. Lokasi yang dikirim hanya kabupaten/kota, bukan alamat rumah atau koordinat. Kolom fakta di preview tidak dapat diedit bebas; hanya rencana tindak lanjut dapat disunting. Refresh membentuk kembali laporan dari database.

```json
{
  "external_source": "survey-gakin",
  "external_reference": "SG-UUID-TRAVEL-LINK",
  "travel_uuid": "22222222-2222-4222-8222-222222222222",
  "daily_activities": [{
    "date": "2026-10-15",
    "start_time": "08:20",
    "end_time": "09:05",
    "description": "Melaksanakan Survey GAKIN terhadap 1 mahasiswa: Nama Contoh.",
    "locations": ["Kabupaten Minahasa"],
    "visits": [{
      "student_name": "Nama Contoh",
      "study_program": "Diploma Tiga Farmasi",
      "location": "Kabupaten Minahasa",
      "started_at": "08:20",
      "completed_at": "09:05"
    }]
  }],
  "target_count": 1,
  "completed_count": 1,
  "result_summary": "Survey GAKIN telah diselesaikan terhadap 1 dari 1 target penugasan.",
  "conclusion": "Seluruh target survey telah selesai.",
  "follow_up": "Verifikasi dan rekapitulasi hasil Survey GAKIN."
}
```

PANGI harus mengabaikan/menolak properti di luar daftar yang disepakati. GAKIN tidak mengirim penghasilan, PKH, kondisi kesehatan, rekomendasi ekonomi individual, checklist, foto, tanda tangan, atau detail GPS.

## Idempotensi dan penguncian

Setiap PUT membawa `Idempotency-Key: GAKIN-{travel-link-uuid}-{sha256-payload}`. Kunci tetap sama untuk retry payload identik. Perubahan isi membuat kunci baru, dengan `external_reference` tetap sama. PANGI perlu memiliki unique constraint untuk `(external_source, external_reference, performer_uuid)` serta tabel penerimaan idempotensi. Di dalam transaction, kunci record draft, cek lock, lalu upsert hanya draft milik pelaksana yang bersangkutan.

Respons sukses: 200/201, misalnya `{"data":{"uuid":"REPORT-UUID","status":"draft","locked":false}}`. Payload sama dengan kunci yang sama harus mengembalikan hasil sebelumnya tanpa duplikasi. 409/423 berarti terkunci atau konflik yang memerlukan pemeriksaan; GAKIN menghentikan retry otomatis. 401/403 untuk token/ownership; 422 untuk validasi; 429/5xx/timeouts dapat dicoba lagi dengan kunci sama.

Klien mengecek status dan kepemilikan ulang sebelum PUT. PANGI tetap harus mengecek lock secara atomik saat PUT karena status dapat berubah setelah GET. Queue GAKIN menahan pengiriman ganda per perjalanan, menggunakan retry 30/120/300 detik dan log yang telah disanitasi.

## Batas kewenangan

Tidak ada endpoint approve, submit laporan final, tanda tangan pimpinan, atau pembuatan QR persetujuan PANGI. Hasil sinkronisasi tetap draft. Pelaksana membuka PANGI untuk memeriksa dan melanjutkan workflow persetujuan resmi di sana. Auto-prepare hanya menyiapkan draft; pengiriman memerlukan centang konfirmasi dan tombol Kirim oleh pemilik laporan.

## Uji penerimaan bersama pengelola PANGI

Uji token valid/kedaluwarsa; UUID pengguna/NIP yang salah; perjalanan orang lain; tanggal tidak relevan; upsert pertama; retry setelah timeout; dua klik bersamaan; perubahan draft; lock di antara GET dan PUT; dan pemeriksaan bahwa daftar field sensitif tidak terkirim. Gunakan staging dan data sintetis sebelum mengaktifkan `PANGI_ENABLED=true` di produksi.
