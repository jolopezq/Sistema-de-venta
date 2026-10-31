<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permisos por persona y protecciones del Super Admin (docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md, Fase 1).
 */
class UserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'password' => 'clave-dueno']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Lourdes']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    private function grant(User $user, array $levels, ?User $as = null)
    {
        $payload = ['permissions' => array_map(fn ($m, $l) => ['module' => $m, 'access_level' => $l], array_keys($levels), $levels)];

        return $this->actingAs($as ?? $this->owner)->putJson("/api/users/{$user->id}/permissions", $payload);
    }

    private function level(User $user, string $module): string
    {
        $service = app(PermissionService::class);
        $service->clearCache();

        return $service->level($user->fresh(), $module);
    }

    public function test_admin_defaults_keep_past_days_and_have_no_finance_or_corrections(): void
    {
        $this->assertSame('edit', $this->level($this->admin, 'sales_history'));
        $this->assertSame('none', $this->level($this->admin, 'finance'));
        $this->assertSame('none', $this->level($this->admin, 'register_corrections'));
        $this->assertSame('none', $this->level($this->cashier, 'sales_history'));
        $this->assertSame('edit', $this->level($this->owner, 'finance'));
    }

    public function test_owner_grants_finance_to_an_admin_and_it_shows_in_me_and_the_users_list(): void
    {
        $this->grant($this->admin, ['finance' => 'read', 'register_corrections' => 'edit'])
            ->assertOk()
            ->assertJsonPath('modules.finance.override', 'read')
            ->assertJsonPath('modules.finance.effective', 'read')
            ->assertJsonPath('modules.finance.role_level', 'none')
            ->assertJsonPath('modules.register_corrections.effective', 'edit');

        $this->assertSame('read', $this->level($this->admin, 'finance'));
        $this->actingAs($this->admin->fresh())->getJson('/api/me')->assertJsonPath('effective_permissions.finance', 'read');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_permissions_changed']);

        $list = $this->actingAs($this->owner)->getJson('/api/users')->assertOk()->json('data');
        $row = collect($list)->firstWhere('id', $this->admin->id);
        $this->assertSame(['finance' => 'read', 'register_corrections' => 'edit'], $row['permission_overrides']);
    }

    public function test_null_level_goes_back_to_the_role_and_an_override_can_also_take_away(): void
    {
        $this->grant($this->admin, ['finance' => 'edit'])->assertOk();
        $this->grant($this->admin, ['finance' => null, 'sales_history' => 'none'])->assertOk()
            ->assertJsonPath('modules.finance.override', null)
            ->assertJsonPath('modules.sales_history.effective', 'none');

        $this->assertSame('none', $this->level($this->admin, 'finance'));
        $this->actingAs($this->admin->fresh())->postJson('/api/admin/sales', [])->assertForbidden();
    }

    public function test_cannot_grant_above_the_role_ceiling_or_to_a_super_admin(): void
    {
        $this->grant($this->cashier, ['finance' => 'read'])->assertStatus(422);
        $this->assertSame('none', $this->level($this->cashier, 'finance'));
        $this->grant($this->admin, ['users' => 'edit'])->assertStatus(422);
        $this->grant($this->owner, ['finance' => 'none'])->assertStatus(422);
    }

    public function test_override_never_exceeds_the_ceiling_even_if_written_directly(): void
    {
        \App\Models\UserPermission::create(['user_id' => $this->cashier->id, 'module' => 'finance', 'access_level' => 'edit']);
        $this->assertSame('none', $this->level($this->cashier, 'finance'));
    }

    public function test_only_the_super_admin_manages_person_permissions(): void
    {
        $this->grant($this->admin, ['finance' => 'edit'], $this->admin)->assertForbidden();
        $this->actingAs($this->admin)->getJson("/api/users/{$this->admin->id}/permissions")->assertForbidden();
    }

    public function test_sellers_list_needs_past_days_permission(): void
    {
        $this->actingAs($this->admin)->getJson('/api/users/sellers')->assertOk()
            ->assertJsonFragment(['id' => $this->cashier->id, 'role' => 'cashier']);
        $this->actingAs($this->cashier)->getJson('/api/users/sellers')->assertForbidden();

        $this->grant($this->admin, ['sales_history' => 'none'])->assertOk();
        $this->actingAs($this->admin->fresh())->getJson('/api/users/sellers')->assertForbidden();
    }

    public function test_naming_a_super_admin_asks_for_the_owners_password_and_closes_their_sessions(): void
    {
        $this->admin->createToken('pos');
        $payload = ['name' => 'Lourdes', 'email' => $this->admin->email, 'role' => 'super_admin'];

        $this->actingAs($this->owner)->putJson("/api/users/{$this->admin->id}", $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->actingAs($this->owner)->putJson("/api/users/{$this->admin->id}", $payload + ['current_password' => 'otra'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);

        $this->actingAs($this->owner)->putJson("/api/users/{$this->admin->id}", $payload + ['current_password' => 'clave-dueno'])
            ->assertOk()->assertJsonPath('data.role', 'super_admin');
        $this->assertSame(0, $this->admin->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'role_changed']);
        $this->assertDatabaseMissing('users', ['id' => $this->admin->id, 'current_password' => 'clave-dueno']);
    }

    public function test_the_last_super_admin_cannot_be_demoted_or_deleted_and_nobody_changes_their_own_role(): void
    {
        $this->actingAs($this->owner)->putJson("/api/users/{$this->owner->id}", [
            'name' => 'Dueño', 'email' => $this->owner->email, 'role' => 'admin', 'current_password' => 'clave-dueno',
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->actingAs($this->owner)->deleteJson("/api/users/{$this->owner->id}")->assertStatus(422);

        // Con un segundo Super Admin: puede degradar al primero, con contraseña, pero no a sí mismo
        $second = User::factory()->create(['role' => 'super_admin', 'password' => 'clave-dos']);
        $this->actingAs($second)->putJson("/api/users/{$this->owner->id}", [
            'name' => 'Dueño', 'email' => $this->owner->email, 'role' => 'admin', 'current_password' => 'clave-dos',
        ])->assertOk();
        $this->actingAs($second)->deleteJson("/api/users/{$second->id}")->assertStatus(422);
    }
}
