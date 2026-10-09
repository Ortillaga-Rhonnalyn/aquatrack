<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterStaffRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterStaffRequest $request): RedirectResponse
    {
        $user = User::query()->create([
            ...$request->safe()->only(['full_name', 'username', 'email', 'password']),
            'role' => 'Staff',
            'status' => 'Active',
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('staff.dashboard');
    }
}
