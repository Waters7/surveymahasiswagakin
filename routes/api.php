<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:60,1'])->get('/me', function (Request $r) {
    abort_unless($r->user()->active && $r->user()->tokenCan('gakin.read-profile'), 403);

    return ['uuid' => $r->user()->uuid, 'name' => $r->user()->name];
});
