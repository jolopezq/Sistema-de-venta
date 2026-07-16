<?php

namespace App\Http\Controllers;

use App\Repositories\UserRepository;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
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
        $user = $this->users->create($request->validated());
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
        $updatedUser = $this->users->update($user, $request->validated());
        return new UserResource($updatedUser);
    }

    /**
     * Soft-delete de un usuario.
     */
    public function destroy(int $id): Response
    {
        $user = $this->users->find($id);
        $this->users->delete($user);
        return response()->noContent();
    }
}
