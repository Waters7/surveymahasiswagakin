<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\PangiController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SurveyController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});
Route::get('/verify/{token}', [ReportController::class, 'verify'])->middleware('throttle:30,1');
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/tasks', [DashboardController::class, 'tasks']);
    Route::get('/map', [DashboardController::class, 'map']);
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::view('/profile', 'profile')->name('profile');
    Route::post('/profile/password', [AuthController::class, 'password'])->name('profile.password');
    Route::post('/profile/read', function () {
        auth()->user()->unreadNotifications->markAsRead();

        return back();
    })->name('profile.read');
    Route::post('/assignments/{assignment}/start', [SurveyController::class, 'start']);
    Route::get('/surveys/{survey}', [SurveyController::class, 'show']);
    Route::get('/surveys/{survey}/state', [SurveyController::class, 'state']);
    Route::post('/surveys/{survey}/draft', [SurveyController::class, 'save'])->middleware('throttle:120,1');
    Route::post('/surveys/{survey}/photos', [SurveyController::class, 'photo']);
    Route::post('/surveys/{survey}/signatures', [SurveyController::class, 'signature']);
    Route::post('/surveys/{survey}/submit', [SurveyController::class, 'submit']);
    Route::post('/surveys/{survey}/verify', [SurveyController::class, 'verify']);
    Route::get('/media/{photo}', [SurveyController::class, 'media']);
    Route::delete('/media/{photo}', [SurveyController::class, 'removePhoto']);
    Route::get('/surveys/{survey}/pdf', [ReportController::class, 'pdf']);
    Route::get('/reports/visits', [ReportController::class, 'visits'])->middleware('can:reports.export');
    Route::get('/reports/excel', [ReportController::class, 'excel'])->middleware('can:reports.export');
    Route::middleware('can:imports.manage')->group(function () {
        Route::get('/imports', [ImportController::class, 'index']);
        Route::post('/imports', [ImportController::class, 'upload']);
        Route::get('/imports/{batch}', [ImportController::class, 'show']);
        Route::post('/imports/{batch}/correct', [ImportController::class, 'correct']);
        Route::post('/imports/{batch}/confirm', [ImportController::class, 'confirm']);
    });
    Route::middleware('can:students.manage')->group(function () {
        Route::get('/students', [AdminController::class, 'students']);
        Route::post('/students', [AdminController::class, 'studentSave']);
        Route::get('/students/{student}/edit', [AdminController::class, 'studentEdit']);
        Route::post('/students/{student}', [AdminController::class, 'studentSave']);
    });
    Route::post('/assignments', [AdminController::class, 'assign'])->middleware('can:assignments.manage');
    Route::middleware('can:users.manage')->group(function () {
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/roles', [AdminController::class, 'roles']);
        Route::post('/roles', [AdminController::class, 'roleSave']);
        Route::post('/users', [AdminController::class, 'userSave']);
        Route::post('/users/{user}', [AdminController::class, 'userSave']);
    });
    Route::get('/masters', [AdminController::class, 'masters'])->middleware('can:masters.manage');
    Route::post('/masters', [AdminController::class, 'masterSave'])->middleware('can:masters.manage');
    Route::get('/audit', [AdminController::class, 'audit'])->middleware('can:audit.view');
    Route::middleware('can:pangi.use')->prefix('pangi')->group(function () {
        Route::get('/', [PangiController::class, 'index']);
        Route::get('/trips', [PangiController::class, 'fetch']);
        Route::post('/connect', [PangiController::class, 'connect']);
        Route::get('/{travel}', [PangiController::class, 'show']);
        Route::post('/{travel}/assign', [PangiController::class, 'assign']);
        Route::post('/{travel}/prepare', [PangiController::class, 'prepare']);
        Route::post('/{travel}/draft', [PangiController::class, 'edit']);
        Route::post('/{travel}/sync', [PangiController::class, 'sync']);
    });
});
