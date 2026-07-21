<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that cashiers cannot access user management.
     */
    public function test_cashier_cannot_access_user_management(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
        ]);

        $response = $this->actingAs($cashier)->getJson('/api/users');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Acceso denegado. Se requiere ser Super Admin.',
        ]);
    }

    /**
     * Test that administrators can manage users (CRUD).
     */
    public function test_admin_can_manage_users(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
        ]);

        // 1. List users
        $response = $this->actingAs($admin)->getJson('/api/users');
        $response->assertStatus(200);

        // 2. Create user
        $userData = [
            'name' => 'Nuevo Cajero',
            'email' => 'cajero@ohana.com',
            'password' => 'secret123',
            'role' => 'cashier',
        ];

        $createResponse = $this->actingAs($admin)->postJson('/api/users', $userData);
        $createResponse->assertStatus(201);
        $createResponse->assertJsonPath('data.email', 'cajero@ohana.com');
        $this->assertDatabaseHas('users', ['email' => 'cajero@ohana.com', 'role' => 'cashier']);

        // 3. Update user
        $createdUser = User::where('email', 'cajero@ohana.com')->first();
        $updateData = [
            'name' => 'Cajero Modificado',
            'email' => 'cajero_mod@ohana.com',
            'role' => 'cashier',
        ];

        $updateResponse = $this->actingAs($admin)->putJson("/api/users/{$createdUser->id}", $updateData);
        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('data.name', 'Cajero Modificado');
        $this->assertDatabaseHas('users', ['id' => $createdUser->id, 'email' => 'cajero_mod@ohana.com']);

        // 4. Delete user
        $deleteResponse = $this->actingAs($admin)->deleteJson("/api/users/{$createdUser->id}");
        $deleteResponse->assertStatus(204);
        $this->assertSoftDeleted('users', ['id' => $createdUser->id]);
    }

    /**
     * Test validation rules.
     */
    public function test_user_validation_rules(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
        ]);

        // Intentar crear usuario sin campos requeridos
        $response = $this->actingAs($admin)->postJson('/api/users', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
    }
}
