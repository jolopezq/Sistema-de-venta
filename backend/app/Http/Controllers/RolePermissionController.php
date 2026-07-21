<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\RolePermission;
use Illuminate\Http\JsonResponse;

class RolePermissionController extends Controller
{
    /**
     * Get all role permissions.
     */
    public function index(): JsonResponse
    {
        return response()->json(RolePermission::all());
    }

    /**
     * Update role permissions in bulk.
     */
    public function update(\App\Http\Requests\RolePermissionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        foreach ($validated['permissions'] as $perm) {
            RolePermission::updateOrCreate(
                ['role' => $perm['role'], 'module' => $perm['module']],
                ['access_level' => $perm['access_level']]
            );
        }

        return response()->json(['message' => 'Permissions updated successfully', 'permissions' => RolePermission::all()]);
    }
}
