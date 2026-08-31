<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Hash;

class SystemBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_and_admin_cannot_download_backup(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($cashier)->postJson('/api/system/backup/download', [
            'password' => 'password123',
        ]);
        $response->assertStatus(403);

        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($admin)->postJson('/api/system/backup/download', [
            'password' => 'password123',
        ]);
        $response->assertStatus(403);
    }

    public function test_super_admin_requires_valid_password(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'password' => Hash::make('correct_password'),
        ]);

        // Sin contraseña
        $response = $this->actingAs($superAdmin)->postJson('/api/system/backup/download', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);

        // Contraseña incorrecta
        $response = $this->actingAs($superAdmin)->postJson('/api/system/backup/download', [
            'password' => 'wrong_password',
        ]);
        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'La contraseña de confirmación es incorrecta.',
        ]);
    }

    public function test_super_admin_can_download_compressed_backup_and_creates_audit_log(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'password' => Hash::make('correct_password'),
        ]);

        $response = $this->actingAs($superAdmin)->postJson('/api/system/backup/download', [
            'password' => 'correct_password',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/gzip');
        $this->assertMatchesRegularExpression(
            '/attachment; filename="?ohana_backup_\d{4}-\d{2}-\d{2}_\d{6}\.sqlite\.gz"?/',
            $response->headers->get('content-disposition')
        );

        // Verificar que se creó el registro de auditoría
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $superAdmin->id,
            'action' => 'download_backup',
            'module' => 'System',
        ]);
    }
}
