<?php

namespace App\Http\Controllers;

use App\Repositories\UserRepository;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly UserRepository $users
    ) {}

    /**
     * Lista paginada de todos los usuarios.
     */
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->paginated());
    }

    /**
     * Crea un nuevo usuario.
     */
    public function store(UserRequest $request): UserResource
    {
        $user = $this->users->create($request->safe()->except('current_password'));
        if ($user->isSuperAdmin()) {
            AuditLogger::log('role_changed', 'Users', "{$user->name} fue creado como Super Admin por {$request->user()->name}.", $request->user()->id);
        }
        return new UserResource($user);
    }

    /**
     * Muestra un usuario específico.
     */
    public function show(int $id): UserResource
    {
        return new UserResource($this->users->find($id));
    }

    /**
     * Actualiza un usuario existente.
     */
    public function update(UserRequest $request, int $id): UserResource
    {
        $user = $this->users->find($id);
        $oldRole = $user->role;
        $updatedUser = $this->users->update($user, $request->safe()->except('current_password'));

        if ($updatedUser->role !== $oldRole) {
            // Entra de nuevo con el rol nuevo: sus permisos y su menú cambian enteros
            $updatedUser->tokens()->delete();
            AuditLogger::log(
                'role_changed',
                'Users',
                "Rol de {$updatedUser->name}: {$oldRole} → {$updatedUser->role}, por {$request->user()->name}.",
                $request->user()->id
            );
        }

        return new UserResource($updatedUser->load('permissionOverrides'));
    }

    /**
     * Resetea la contraseña de un usuario.
     */
    public function resetPassword(\Illuminate\Http\Request $request, int $id): Response
    {
        $request->validate(['password' => 'required|string|min:6']);
        $user = $this->users->find($id);
        
        $currentUser = $request->user();
        if ($currentUser->role === 'admin' && $user->role !== 'cashier') {
            abort(403, 'Unauthorized to reset password for this user.');
        }

        $this->users->update($user, ['password' => $request->password]);
        return response()->noContent();
    }

    /**
     * Soft-delete de un usuario.
     */
    public function destroy(\Illuminate\Http\Request $request, int $id): Response
    {
        $user = $this->users->find($id);
        if ($user->id === $request->user()->id) {
            abort(422, 'No puedes eliminar tu propia cuenta.');
        }
        if ($user->isSuperAdmin() && User::where('role', 'super_admin')->whereKeyNot($user->id)->doesntExist()) {
            abort(422, 'Es el único Super Admin: no se puede eliminar.');
        }
        $this->users->delete($user);
        $user->tokens()->delete();
        // Quien deja de trabajar: se borra su rostro registrado y deja de aparecer en el reloj. Sus marcas quedan.
        foreach (\App\Models\FaceEnrollment::active()->where('user_id', $user->id)->get() as $enrollment) {
            if ($enrollment->photo_path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($enrollment->photo_path);
            }
            $enrollment->forceFill(['revoked_at' => now(), 'photo_path' => null, 'descriptor' => []])->save();
        }
        \App\Models\EmployeeProfile::where('user_id', $user->id)->update(['attendance_enabled' => false]);
        return response()->noContent();
    }

    /**
     * Quienes pueden figurar como vendedores en Control diario (cajeros y administradores activos).
     * Lo usa el editor de ventas de días pasados, que no tiene acceso a la gestión de usuarios.
     */
    public function sellers(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'data' => User::whereIn('role', ['super_admin', 'admin', 'cashier'])
                ->orderBy('name')
                ->get(['id', 'name', 'role']),
        ]);
    }
}
