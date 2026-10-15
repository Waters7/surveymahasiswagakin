@props(['survey'])
@php($criteria = collect($survey->criteria_snapshot)->keyBy('id'))
<div class="documentation-gallery">
    <h3>Foto yang sudah diunggah</h3>
    <div class="photo-grid documentation-grid">
        @foreach($survey->photos->sortBy('id') as $photo)
            @php($criterion = $photo->criterion_id ? $criteria->get($photo->criterion_id) : null)
            <figure class="photo-item">
                @if($photo->isPdf())<a class="photo-file" href="/media/{{$photo->uuid}}" target="_blank" rel="noopener">PDF</a>@else<a href="/media/{{$photo->uuid}}" target="_blank" rel="noopener"><img src="/media/{{$photo->uuid}}" alt="{{$photo->label()}}" loading="lazy"></a>@endif
                <figcaption><span class="photo-tag ok">Terkirim</span><strong>{{$criterion ? 'Bukti kriteria '.$criterion['position'] : $photo->label()}}</strong>@if($criterion)<small>{{\Illuminate\Support\Str::limit($criterion['label'], 70)}}</small>@endif</figcaption>
                <button type="button" class="btn danger" @click="deletePhoto('{{$photo->uuid}}',$el)">Hapus</button>
            </figure>
        @endforeach
        <template x-for="item in uploaded" :key="item.key">
            <figure class="photo-item"><img x-show="item.url" :src="item.url" :alt="item.label"><figcaption><span class="photo-tag ok">Baru terkirim</span><strong x-text="item.label"></strong></figcaption></figure>
        </template>
    </div>
    @if($survey->photos->isEmpty())<p class="muted" x-show="!uploaded.length">Belum ada foto yang terkirim ke server.</p>@endif
    <h3 x-show="queued.length" x-cloak>Menunggu dikirim dari perangkat (<span x-text="queued.length"></span>)</h3>
    <div class="photo-grid documentation-grid" x-show="queued.length" x-cloak>
        <template x-for="item in queued" :key="item.id">
            <figure class="photo-item"><img x-show="item.url" :src="item.url" :alt="item.label"><figcaption><span class="photo-tag wait">Antre di perangkat</span><strong x-text="item.label"></strong></figcaption></figure>
        </template>
    </div>
</div>
