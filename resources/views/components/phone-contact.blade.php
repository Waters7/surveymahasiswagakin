@props(['number' => null, 'whatsapp' => false])
@php
    $international = \App\Services\ImportService::phone($number);
    $isIndonesian = $international && str_starts_with($international, '62');
    $local = $isIndonesian ? '0'.substr($international, 2) : $international;
@endphp
@if($international)
<span {{ $attributes->class('phone-contact') }}><a href="tel:{{$isIndonesian ? '+' : ''}}{{$international}}" aria-label="Telepon {{$local}}">{{$isIndonesian ? implode('-', str_split($local, 4)) : $local}}</a>@if($whatsapp)<a href="https://wa.me/{{$international}}" target="_blank" rel="noopener noreferrer">WhatsApp</a>@endif</span>
@else
<span {{ $attributes->class('muted') }}>Belum ada nomor HP</span>
@endif
