<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiLoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiAuthenticationController extends Controller
{
    public function store(ApiLoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('username', $request->string('username')->toString())
            ->where('status', 'Active')
            ->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }

        $plainTextToken = Str::random(80);
        $user->forceFill(['api_token' => hash('sha256', $plainTextToken)])->save();

        return response()->json([
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->user_id,
                'full_name' => $user->full_name,
                'username' => $user->username,
                'role' => $user->role,
            ],
        ]);
    }
}
