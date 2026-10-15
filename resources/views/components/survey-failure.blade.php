@props(['assignment'])
@php
    $failure = $assignment->status === \App\Enums\SurveyStatus::Failed->value ? $assignment->latestFailure : null;
    $canFail = $assignment->user_id === auth()->id() && auth()->user()->can('surveys.work') && ! $assignment->survey?->submitted_at;
    $failErrors = $errors->getBag('failure-'.$assignment->uuid);
    $formId = 'gagal-'.$assignment->uuid;
@endphp
@if($failure)
    <div class="failure-note" role="note">
        <strong>Gagal survey · {{$failure->occurred_at->format('d M Y H:i')}} WITA</strong>
        <span>{{$failure->reasonText()}}@if($failure->reason !== \App\Enums\SurveyFailureReason::Other && $failure->notes) — {{$failure->notes}}@endif</span>
        @if($failure->evidence)
            <span class="failure-evidence">@foreach($failure->evidence as $index => $file)<a class="text-link" href="/survey-failures/{{$failure->uuid}}/evidence/{{$index}}" target="_blank" rel="noopener">Bukti {{$index + 1}}</a>@endforeach</span>
        @endif
    </div>
@endif
@if($canFail)
    <details class="failure-form" @if($failErrors->any()) open @endif>
        <summary class="btn danger"><x-icon name="circle-x" size="16"/>Gagal survey</summary>
        <form method="post" action="/assignments/{{$assignment->uuid}}/fail" enctype="multipart/form-data">
            @csrf
            <fieldset class="field">
                <legend>Alasan survey gagal</legend>
                <div class="choice-row">
                    @foreach(\App\Enums\SurveyFailureReason::cases() as $reason)
                        <label class="choice"><input type="radio" name="reason" value="{{$reason->value}}" required @checked($failErrors->any() && old('reason') === $reason->value)>{{$reason->label()}}</label>
                    @endforeach
                </div>
                @if($failErrors->has('reason'))<small class="field-error">{{$failErrors->first('reason')}}</small>@endif
            </fieldset>
            <div class="field">
                <label for="{{$formId}}-notes">Keterangan <span class="muted">(wajib untuk alasan lainnya)</span></label>
                <input id="{{$formId}}-notes" name="notes" maxlength="200" value="{{$failErrors->any() ? old('notes') : ''}}" placeholder="Mis. sudah ditelepon 3 kali, nomor tidak aktif" @if($failErrors->has('notes')) aria-invalid="true" @endif>
                @if($failErrors->has('notes'))<small class="field-error">{{$failErrors->first('notes')}}</small>@endif
            </div>
            <div class="field">
                <label for="{{$formId}}-bukti">Bukti <span class="muted">(opsional, maks. 3 gambar)</span></label>
                <input id="{{$formId}}-bukti" type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple>
                <small class="muted">Screenshot riwayat panggilan atau chat, atau foto rumah/lokasi.</small>
                @foreach(['evidence', 'evidence.0', 'evidence.1', 'evidence.2'] as $field)
                    @if($failErrors->has($field))<small class="field-error">{{$failErrors->first($field)}}</small>@endif
                @endforeach
            </div>
            <p class="muted failure-privacy">Nama mahasiswa dan alasan dicantumkan pada draft laporan perjalanan PANGI. Bukti gambar tidak dikirim ke PANGI.</p>
            <button class="btn danger">Simpan survey gagal</button>
        </form>
    </details>
@endif
