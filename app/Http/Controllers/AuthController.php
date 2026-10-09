<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $r): RedirectResponse
    {
        $data = $r->validate(['login' => 'required|string', 'password' => 'required|string']);
        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'nip';
        if (! Auth::attempt([$field => $data['login'], 'password' => $data['password'], 'active' => true])) {
            return back()->withErrors(['login' => 'Identitas atau password tidak sesuai, atau akun belum aktif.'])->onlyInput('login');
        }
        $r->session()->regenerate();
        Audit::record('auth.login', $r->user());

        return redirect()->intended('/');
    }

    public function logout(Request $r): RedirectResponse
    {
        Audit::record('auth.logout', $r->user());
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login')->withHeaders(['Clear-Site-Data' => '"cache", "storage"']);
    }

    public function password(Request $r): RedirectResponse
    {
        $d = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
        $r->user()->update(['password' => $d['password'], 'must_change_password' => false]);
        $r->session()->regenerate();
        Audit::record('auth.password_changed', $r->user());

        return back()->with('status', 'Password diperbarui.');
    }
}
