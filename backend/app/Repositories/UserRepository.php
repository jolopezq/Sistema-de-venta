<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

/**
 * Repositorio de Usuarios.
 *
 * Centraliza consultas a la tabla `users` para mantener
 * los controladores limpios y facilitar el testing.
 *
 * Patrón: Repository Pattern (PHP The Right Way / buenas-practicas.md §5)
 */
class UserRepository
{
    /**
     * Lista paginada de todos los usuarios (incluye soft deleted si es necesario, pero por ahora activos).
     *
     * @param int $perPage Elementos por página.
     */
    public function paginated(int $perPage = 20): LengthAwarePaginator
    {
        return User::orderBy('name')->paginate($perPage);
    }

    /**
     * Busca un usuario por ID o lanza excepción.
     */
    public function find(int $id): User
    {
        return User::findOrFail($id);
    }

    /**
     * Crea un nuevo usuario en la base de datos.
     */
    public function create(array $data): User
    {
        // El PIN se cifra solo si es necesario, pero la arquitectura/diseño dice:
        // users.pin es de texto plano para comparaciones rápidas o Hash.
        // Siguiendo el estándar, use casts en el modelo o encriptamos aquí.
        // Como pin se usa en el backend para verificar anulaciones rápidas (ej. SHA-256 o texto plano según decisión anterior).
        // En User.php no tiene casts para pin, así que lo guardamos como texto plano o hashed.
        // En User.php pin está en $hidden. Vamos a guardarlo como texto plano o encriptado.
        // Dado que es un PIN corto (4 dígitos), encriptarlo con Hash::make es lo más seguro.
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        if (isset($data['pin'])) {
            // Guardamos el PIN encriptado para mayor seguridad
            $data['pin'] = Hash::make($data['pin']);
        }

        return User::create($data);
    }

    /**
     * Actualiza un usuario existente.
     */
    public function update(User $user, array $data): User
    {
        if (isset($data['password']) && !empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if (isset($data['pin']) && !empty($data['pin'])) {
            $data['pin'] = Hash::make($data['pin']);
        } else {
            unset($data['pin']);
        }

        $user->update($data);
        return $user->fresh();
    }

    /**
     * Realiza soft-delete de un usuario.
     */
    public function delete(User $user): void
    {
        $user->delete();
    }
}
