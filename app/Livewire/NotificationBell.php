<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class NotificationBell extends Component
{
    public function render(): View
    {
        return view('livewire.notification-bell', ['count' => auth()->user()?->unreadNotifications()->count() ?? 0]);
    }
}
