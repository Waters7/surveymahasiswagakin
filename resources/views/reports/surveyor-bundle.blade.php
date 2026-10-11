<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><title>Laporan hasil Survey GAKIN</title><style>
@include('reports.survey-styles')
.bundle-cover h1{font-size:18px;margin:24px 0 12px}.bundle-cover h2{font-size:13px}.bundle-cover table{margin-top:18px}.bundle-cover td,.bundle-cover th{padding:8px}.bundle-survey-heading{font-size:9px;border-bottom:1px solid #999;padding-bottom:5px;margin-bottom:10px;color:#444}
</style></head>
<body>
<div class="bundle-cover">
    <h1>LAPORAN HASIL SURVEY GAKIN</h1>
    <p style="text-align:center">POLTEKKES KEMENKES MANADO</p>
    <p>Periode: <strong>{{$period?->name ?? 'Semua periode'}}</strong><br>Dicetak: {{$printedAt->format('d-m-Y H:i')}} WITA<br>Jumlah hasil survey: <strong>{{$documents->count()}}</strong></p>
    <table><thead><tr><th>Surveyor</th><th>Jumlah hasil terkirim</th></tr></thead><tbody>
        @foreach($documents->groupBy('survey.user_id') as $group)<tr><td>{{$group->first()['survey']->user->name}}</td><td>{{$group->count()}}</td></tr>@endforeach
    </tbody></table>
    <p>Dokumen ini memuat seluruh hasil terkirim sesuai filter, termasuk hasil yang menunggu verifikasi. Status verifikasi tercantum pada setiap formulir. Draft dan hasil yang sedang direvisi tidak disertakan.</p>
    <p>Setiap formulir dilengkapi hasil kriteria, rekomendasi, tanda tangan, QR verifikasi, dan lampiran yang tersedia. Lampiran PDF penerima PKH dapat dibuka melalui tautan berizin pada formulir.</p>
</div>
@foreach($documents as $document)
<div class="page"></div>
<p class="bundle-survey-heading">Hasil {{$loop->iteration}} dari {{$documents->count()}} · {{$document['survey']->user->name}} · {{$document['survey']->assignment?->period?->name}}</p>
@include('reports.survey-body', $document)
@endforeach
</body></html>
