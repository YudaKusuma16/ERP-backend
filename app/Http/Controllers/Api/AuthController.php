<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Log percobaan login gagal (kredensial salah)
            Log::channel('activity')->warning('[AUTH] Login GAGAL - Kredensial tidak valid', [
                'email'      => $request->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            // Log percobaan login gagal (akun nonaktif)
            Log::channel('activity')->warning('[AUTH] Login GAGAL - Akun nonaktif', [
                'user_id'    => $user->id,
                'email'      => $user->email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['Your account is inactive.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        // Log login berhasil
        Log::channel('activity')->info('[AUTH] User LOGIN berhasil', [
            'user_id'    => $user->id,
            'user_name'  => $user->name,
            'user_email' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'user'  => $user->load('department', 'roles'),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Log logout sebelum token dihapus
        Log::channel('activity')->info('[AUTH] User LOGOUT', [
            'user_id'    => $request->user()->id,
            'user_name'  => $request->user()->name,
            'user_email' => $request->user()->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->load('department', 'roles'),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'department_id' => 'nullable|exists:departments,id',
            'employee_id' => 'nullable|string|unique:users,employee_id',
            'phone' => 'nullable|string|max:20',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'department_id' => $request->department_id,
            'employee_id' => $request->employee_id,
            'phone' => $request->phone,
        ]);

        if ($request->has('roles')) {
            $roles = Role::whereIn('code', $request->roles)->get();
            $user->roles()->attach($roles);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user->load('department', 'roles'),
            'token' => $token,
        ], 201);
    }
}