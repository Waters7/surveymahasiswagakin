@props(['kind', 'criterion' => null, 'serverCount' => 0])
@php
    $id = 'foto-'.$kind.'-'.($criterion ?? 'umum');
    $target = $criterion === null ? 'null' : (int) $criterion;
    $match = "(item) => item.kind === '{$kind}' && item.criterion === {$target}";
@endphp
<div {{ $attributes->class('photo-picker') }}>
    <div class="photo-picker-actions">
        <label class="btn" for="{{$id}}-kamera"><x-icon name="camera" size="16"/>Kamera</label>
        <input id="{{$id}}-kamera" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" @change="queuePhotos($event,{{$target}},'{{$kind}}')">
        <label class="btn" for="{{$id}}-file"><x-icon name="image-up" size="16"/>Galeri / file</label>
        <input id="{{$id}}-file" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" multiple @change="queuePhotos($event,{{$target}},'{{$kind}}')">
    </div>
    <small class="muted" x-text="({{(int) $serverCount}} + uploaded.filter({{$match}}).length) + ' foto terkirim' + (queued.filter({{$match}}).length ? ' · ' + queued.filter({{$match}}).length + ' antre di perangkat' : '')">{{(int) $serverCount}} foto terkirim</small>
</div>
