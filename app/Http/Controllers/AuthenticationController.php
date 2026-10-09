<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticationController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->safe()->only(['username', 'password']);

        if (! Auth::attempt([...$credentials, 'status' => 'Active'], $request->boolean('remember'))) {
            return back()
                ->withErrors(['username' => 'The provided credentials do not match our records.'])
                ->onlyInput('username');
        }

        $request->session()->regenerate();

        if (in_array($request->user()->role, ['Staff', 'Delivery Staff'], true)) {
            return redirect()->intended(route('staff.dashboard', absolute: false));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
