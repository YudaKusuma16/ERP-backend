<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with('department', 'roles');

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%')
                    ->orWhere('employee_id', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $users = $query->orderBy('name')->paginate($request->per_page ?? 20);

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'user' => $user->load('department', 'roles'),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'department_id' => 'nullable|exists:departments,id',
            'employee_id' => 'nullable|string|unique:users,employee_id,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        $user->update($validated);

        if ($request->has('roles')) {
            $roleIds = \App\Models\Role::whereIn('code', $request->roles)->pluck('id');
            $user->roles()->sync($roleIds);
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user->fresh()->load('department', 'roles'),
        ]);
    }

    public function assignRoles(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,code',
        ]);

        $roleIds = \App\Models\Role::whereIn('code', $validated['roles'])->pluck('id');
        $user->roles()->sync($roleIds);

        return response()->json([
            'message' => 'Roles assigned successfully.',
            'user' => $user->fresh()->load('department', 'roles'),
        ]);
    }
}