<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkPattern;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Smoke test: setiap halaman panel admin terbuka tanpa error. */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public static function pages(): array
    {
        return array_map(fn ($p) => [$p], [
            '/admin', '/admin/employees', '/admin/employees/create', '/admin/employees/{employee}/edit',
            '/admin/units', '/admin/offices', '/admin/work-patterns', '/admin/work-patterns/create', '/admin/work-patterns/{pattern}/edit',
            '/admin/schedule-overrides', '/admin/calendar-events', '/admin/attendances', '/admin/leave-requests', '/admin/attendance-attempts',
            '/admin/exit-permits', '/admin/presence-checks', '/admin/attendance-corrections',
        ]);
    }

    #[DataProvider('pages')]
    public function test_admin_can_open_page(string $url): void
    {
        // ID di Postgres tidak kembali ke 1 antar test, jadi diisi saat berjalan.
        $url = strtr($url, ['{employee}' => Employee::value('id'), '{pattern}' => WorkPattern::value('id')]);
        $this->actingAs(User::where('email', 'admin@kampus.test')->sole())->get($url)->assertOk();
    }

    public function test_unit_head_sees_only_monitoring_pages(): void
    {
        $head = User::where('email', 'dekan.ft@kampus.test')->sole();

        $this->actingAs($head)->get('/admin')->assertOk();
        $this->actingAs($head)->get('/admin/attendances')->assertOk();
        $this->actingAs($head)->get('/admin/leave-requests')->assertOk();
        $this->actingAs($head)->get('/admin/offices')->assertForbidden();
        $this->actingAs($head)->get('/admin/employees/create')->assertForbidden();
    }

    public function test_regular_employee_cannot_open_panel(): void
    {
        $this->actingAs(User::where('email', 'tendik@kampus.test')->sole())->get('/admin')->assertForbidden();
    }
}
