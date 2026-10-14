@if($survey->settings_snapshot['demo'] ?? false)<p style="border:2px solid #965c00;padding:6px;text-align:center;font-size:11px"><strong>CONTOH LATIHAN — BUKAN DOKUMEN RESMI</strong><br>Identitas, lokasi, bukti dan tanda tangan adalah simulasi.</p>@endif
<div class="print-header">
    <div class="print-letterhead"><img src="data:image/png;base64,{{base64_encode(file_get_contents(public_path('form-letterhead.png')))}}" alt="Kementerian Kesehatan, Poltekkes Manado"></div>
    <table class="meta">
        <tr>
            <td class="meta-logo"><img src="data:image/png;base64,{{base64_encode(file_get_contents(public_path('form-logo.png')))}}" alt="Kemenkes Poltekkes Manado"></td>
            <td class="meta-content"><table class="meta-details"><tr><td class="meta-institution" colspan="3">POLTEKKES KEMENKES MANADO</td></tr>
        <tr><td class="meta-label">No. Dokumen</td><td class="meta-colon">:</td><td class="meta-value">{{$survey->settings_snapshot['document_number']}}</td></tr>
        <tr><td class="meta-label">Tanggal Ditetapkan</td><td class="meta-colon">:</td><td class="meta-value">{{\Carbon\CarbonImmutable::parse($survey->settings_snapshot['document_date'])->locale('id')->translatedFormat('j F Y')}}</td></tr>
        <tr><td class="meta-label">Revisi</td><td class="meta-colon">:</td><td class="meta-value">{{$survey->settings_snapshot['document_revision']}} Ed. {{$survey->settings_snapshot['document_edition']}}</td></tr>
        <tr><td class="meta-title" colspan="3"><h1>@if(str_starts_with($survey->settings_snapshot['document_title'], 'FORMULIR SURVEY KRITERIA '))FORMULIR SURVEY KRITERIA<br>{{substr($survey->settings_snapshot['document_title'], strlen('FORMULIR SURVEY KRITERIA '))}}@else{{$survey->settings_snapshot['document_title']}}@endif<br>POLTEKKES KEMENKES MANADO<br>TAHUN {{$survey->started_at->format('Y')}}</h1></td></tr>
            </table></td>
        </tr>
    </table>
</div>
<p>Nama Calon Penerima GAKIN: <strong>{{$survey->student_snapshot['name']}}</strong><br>NIM / No. Pendaftaran: {{$survey->student_snapshot['nim']??$survey->student_snapshot['registration_number']}}<br>Pilihan Prodi: {{$survey->student_snapshot['program']['name']}}<br>Alamat: {{$survey->student_snapshot['address']['address']??'—'}}</p>
<table class="criteria"><thead><tr><th style="width:4%">No</th><th style="width:50%">Kriteria GAKIN</th><th style="width:15%">Hasil Survey</th><th>Rekomendasi / Keterangan</th></tr></thead><tbody>@foreach($survey->criteria_snapshot as $c)@php($a=$survey->answers->firstWhere('criterion_id',$c['id']))<tr><td>{{$c['position']}}</td><td>{{$c['label']}}</td><td>{{ucfirst(str_replace('_',' ',$a?->answer??''))}}</td><td>{{$a?->detail}} {{$a?->notes}}</td></tr>@endforeach</tbody></table>
<p>* Pemegang kartu Program Keluarga Harapan (PKH) dapat direkomendasikan sesuai ketentuan formulir.<br>Status PKH: {{$survey->pkh?'Ya':'Tidak'}}</p><p><strong>Rekomendasi akhir surveyor: {{strtoupper(str_replace('_',' ',$survey->recommendation))}}</strong><br>{{$survey->conclusion}}</p><p>Status verifikasi: {{str_replace('_',' ',$survey->status)}}<br>Tanggal survey: {{$survey->submitted_at->format('d-m-Y')}} WITA</p>
<div class="signoff">@if($survey->isVideoCall())<p class="caption">Survey dilaksanakan melalui video call; isian dan tanda tangan dilakukan oleh surveyor.</p>@endif<table class="signatures"><tr><td>Mengetahui,<br>Yang melaksanakan survey,</td>@unless($survey->isVideoCall())<td>Mengetahui,<br>Orang Tua Calon Pendaftar / Wali,</td>@endunless</tr><tr>@foreach($survey->isVideoCall() ? ['surveyor'] : ['surveyor','parent'] as $role)<td>@if(isset($signatures[$role]))<img src="{{$signatures[$role]['image']}}"><br>{{$signatures[$role]['name']}}@else<br><br>Tidak tersedia
@endif</td>@endforeach</tr></table><footer><img src="{{$qr}}" style="width:48px;vertical-align:middle"> Verifikasi dokumen · {{$survey->uuid}}<br>Hash: {{$survey->document_hash}}</footer></div>
@if($photos->isNotEmpty())
<div class="attachments">
    @foreach($photos as $p)
    <div class="attachment-group">
        @if($loop->first)<h1>LAMPIRAN DOKUMENTASI SURVEY</h1><p>{{$survey->student_snapshot['name']}} · {{$survey->uuid}}</p><p>@if($survey->isVideoCall())Metode: survey video call (tanpa GPS)@else Lokasi aktual: {{$survey->location?->latitude}}, {{$survey->location?->longitude}} · Akurasi {{$survey->location?->accuracy}} m @endif</p>@endif
        @if($p['image'])<img class="evidence" src="{{$p['image']}}">@else<p><strong>Dokumen PDF: {{$p['filename']}}</strong><br><a href="{{$p['url']}}">Buka lampiran asli (perlu login)</a></p>@endif
        <p class="caption">{{$p['caption']}}</p>
    </div>
    @endforeach
</div>
@endif
