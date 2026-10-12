@props(['placement' => 'bottom', 'triggerClass' => ''])
@php($user = auth()->user())
<div {{ $attributes->class(['user-menu', 'user-menu-'.$placement]) }} x-data="{open:false}" @click.outside="open=false" @keydown.escape="if(open){open=false;$refs.trigger.focus()}">
    <button type="button" class="user-menu-trigger {{ $triggerClass }}" x-ref="trigger" @click="open=!open" :aria-expanded="open.toString()" aria-expanded="false" aria-controls="user-menu-{{ $placement }}" aria-label="Menu akun {{ $user->name }}">{{ $slot }}</button>
    <div class="user-menu-panel" id="user-menu-{{ $placement }}" x-show="open" x-cloak x-transition.opacity.duration.120ms>
        <div class="user-menu-head"><strong>{{ $user->name }}</strong><small>{{ $user->email ?: $user->nip }}</small></div>
        <a class="user-menu-item" href="/profile"><x-icon name="user-round" size="16"/>Profil & password</a>
        <form method="post" action="{{ route('logout') }}" data-logout-form>@csrf<button type="submit" class="user-menu-item danger"><x-icon name="log-out" size="16"/>Keluar</button></form>
    </div>
</div>
