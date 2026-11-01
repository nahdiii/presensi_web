<?php

namespace Tests\Feature;

use App\Enums\EmployeeType;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsCampus;
use Tests\TestCase;

/** Peran Super Admin tidak terlihat dan tidak bisa disentuh oleh peran di bawahnya. */
class SuperAdminTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    private User $super;

    private User $admin;

    private Employee $superEmployee;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();
        // ROLE_SUPER sengaja tidak dibuat di sini: harus sudah ada dari migration.
        foreach ([User::ROLE_ADMIN, User::ROLE_HEAD, User::ROLE_EMPLOYEE] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->buildCampus();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->super = $this->makeEmployee()->user;
        $this->super->assignRole(User::ROLE_SUPER);
        $this->superEmployee = $this->super->employee;
        $this->admin = $this->makeEmployee()->user;
        $this->admin->assignRole(User::ROLE_ADMIN);
        $this->staff = $this->makeEmployee();
        $this->staff->user->assignRole(User::ROLE_EMPLOYEE);
    }

    /** @return array<string, mixed> */
    private function newEmployeeForm(array $overrides = []): array
    {
        return $overrides + [
            'user_name' => 'Pegawai Baru', 'user_email' => 'baru@kampus.test', 'user_password' => 'password',
            'user_roles' => [User::ROLE_EMPLOYEE], 'user_is_active' => true,
            'employee_type' => EmployeeType::Tendik->value, 'attendance_type' => 'fixed',
            'offices' => [$this->kampus1->id],
        ];
    }

    public function test_super_admin_has_admin_powers(): void
    {
        $this->assertTrue($this->super->isAdmin());
        $this->assertNull($this->super->supervisedUnitIds());
        $this->assertTrue($this->super->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_admin_is_not_super_admin(): void
    {
        $this->assertTrue($this->admin->isAdmin());
        $this->assertFalse($this->admin->isSuperAdmin());
    }

    public function test_role_option_is_only_shown_to_super_admin(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateEmployee::class)->assertDontSee('Super Admin');

        $this->actingAs($this->super);
        Livewire::test(CreateEmployee::class)->assertSee('Super Admin');
    }

    public function test_panel_identity_only_says_super_admin_to_super_admin(): void
    {
        $this->actingAs($this->super)->get('/admin')->assertOk()->assertSee('Super Admin');
    }

    public function test_panel_identity_says_admin_sdm_to_admin(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Admin SDM')->assertDontSee('Super Admin');
    }

    public function test_admin_cannot_see_super_admin_account_in_employee_list(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ListEmployees::class)
            ->assertCanSeeTableRecords([$this->staff])
            ->assertCanNotSeeTableRecords([$this->superEmployee]);

        $this->actingAs($this->super);
        Livewire::test(ListEmployees::class)->assertCanSeeTableRecords([$this->superEmployee, $this->staff]);
    }

    public function test_admin_cannot_open_super_admin_account(): void
    {
        $this->actingAs($this->admin)
            ->get(EmployeeResource::getUrl('edit', ['record' => $this->superEmployee], panel: 'admin'))
            ->assertNotFound();
    }

    public function test_admin_cannot_grant_super_admin_by_tampering_create_form(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(CreateEmployee::class)
            ->fillForm($this->newEmployeeForm(['user_roles' => [User::ROLE_SUPER]]))
            ->call('create');

        $this->assertFalse(User::where('email', 'baru@kampus.test')->exists());
    }

    public function test_admin_cannot_grant_super_admin_by_tampering_edit_form(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(EditEmployee::class, ['record' => $this->staff->getRouteKey()])
            ->fillForm(['user_roles' => [User::ROLE_SUPER]])
            ->call('save');

        $this->assertFalse($this->staff->user->fresh()->isSuperAdmin());
    }

    public function test_super_admin_can_create_super_admin(): void
    {
        $this->actingAs($this->super);
        Livewire::test(CreateEmployee::class)
            ->fillForm($this->newEmployeeForm(['user_roles' => [User::ROLE_SUPER]]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(User::where('email', 'baru@kampus.test')->firstOrFail()->isSuperAdmin());
    }

    public function test_super_admin_can_grant_super_admin(): void
    {
        $this->actingAs($this->super);
        Livewire::test(EditEmployee::class, ['record' => $this->staff->getRouteKey()])
            ->fillForm(['user_roles' => [User::ROLE_SUPER]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->staff->user->fresh()->isSuperAdmin());
    }

    public function test_command_creates_account(): void
    {
        $this->artisan('super-admin:create', ['email' => 'pemilik@kampus.test'])
            ->expectsQuestion('Password (minimal 8 karakter)', 'rahasia-panjang')
            ->assertSuccessful();

        $user = User::where('email', 'pemilik@kampus.test')->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Hash::check('rahasia-panjang', $user->password));
        $this->assertSame($this->inst->id, $user->institution_id);
    }

    public function test_command_promotes_existing_account_and_keeps_name(): void
    {
        $this->artisan('super-admin:create', ['email' => $this->admin->email])
            ->expectsQuestion('Password (minimal 8 karakter)', 'rahasia-panjang')
            ->assertSuccessful();

        $fresh = $this->admin->fresh();
        $this->assertTrue($fresh->isSuperAdmin());
        $this->assertSame($this->admin->name, $fresh->name);
    }

    public function test_command_rejects_short_password(): void
    {
        $this->artisan('super-admin:create', ['email' => 'pemilik@kampus.test'])
            ->expectsQuestion('Password (minimal 8 karakter)', 'pendek')
            ->assertFailed();

        $this->assertFalse(User::where('email', 'pemilik@kampus.test')->exists());
    }
}
