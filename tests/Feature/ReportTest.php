<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Exports\MonthlyAttendanceExport;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Ekspor rekap bulanan dan filter unit di daftar Presensi. Unit contoh:
 * Fakultas Teknik (Budi) → Prodi Informatika (Fauzi); Biro SDM (Siti) terpisah.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private string $month;

    /** @var array<string, Attendance> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Bulan lalu selalu ada di pilihan bulan ekspor.
        $this->month = now(config('attendance.timezone'))->startOfMonth()->subMonth()->format('Y-m');

        foreach (['tendik.ft@kampus.test' => AttendanceStatus::Absent, 'dosen@kampus.test' => AttendanceStatus::Absent,
            'tendik@kampus.test' => AttendanceStatus::Absent] as $email => $status) {
            $employee = Employee::whereHas('user', fn ($q) => $q->where('email', $email))->sole();
            $this->rows[$email] = Attendance::create([
                'institution_id' => $employee->institution_id, 'employee_id' => $employee->id,
                'date' => "{$this->month}-15", 'status' => $status,
            ]);
        }
    }

    private function admin(): User
    {
        return User::where('email', 'admin@kampus.test')->sole();
    }

    public function test_admin_can_download_monthly_export(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ListAttendances::class)
            ->callAction('export', ['month' => $this->month])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded("rekap-presensi-{$this->month}.xlsx");
    }

    public function test_export_contains_recap_details_and_deductions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rekap');
        file_put_contents($path, Excel::raw(new MonthlyAttendanceExport($this->month), ExcelWriter::XLSX));
        $book = IOFactory::load($path);
        unlink($path);

        $this->assertSame(["Rekap {$this->month}", 'Rincian harian'], $book->getSheetNames());

        $recap = collect($book->getSheet(0)->toArray())->keyBy(2); // Kolom C: nama.
        $this->assertSame(1, (int) $recap['Siti Aminah, S.E.'][10]); // Alpa
        $this->assertSame(50000, (int) $recap['Siti Aminah, S.E.'][19]); // Total potongan (Rp)

        $this->assertCount(3 + 1, $book->getSheet(1)->toArray()); // 3 baris + judul kolom
    }

    public function test_unit_head_export_only_contains_supervised_units(): void
    {
        $head = User::where('email', 'dekan.ft@kampus.test')->sole();
        $book = new MonthlyAttendanceExport($this->month, $head->supervisedUnitIds());
        $names = collect($book->sheets()[1]->array())->pluck(2)->unique()->sort()->values()->all();

        $this->assertSame(['Ahmad Fauzi, M.Kom.', 'Budi Santoso'], $names);
    }

    public function test_unit_filter_includes_sub_units(): void
    {
        $this->actingAs($this->admin());
        $faculty = Unit::where('name', 'Fakultas Teknik')->sole();
        $prodi = Unit::where('name', 'Prodi Informatika')->sole();

        Livewire::test(ListAttendances::class)
            ->filterTable('unit', $faculty->id)
            ->assertCanSeeTableRecords([$this->rows['tendik.ft@kampus.test'], $this->rows['dosen@kampus.test']])
            ->assertCanNotSeeTableRecords([$this->rows['tendik@kampus.test']])
            ->filterTable('unit', $prodi->id)
            ->assertCanSeeTableRecords([$this->rows['dosen@kampus.test']])
            ->assertCanNotSeeTableRecords([$this->rows['tendik.ft@kampus.test'], $this->rows['tendik@kampus.test']]);
    }

    public function test_unit_head_is_only_offered_own_units(): void
    {
        $this->actingAs(User::where('email', 'dekan.ft@kampus.test')->sole());

        Livewire::test(ListAttendances::class)
            ->assertTableFilterExists('unit', fn ($filter) => array_values($filter->getOptions())
                === ['Fakultas Teknik', 'Prodi Informatika']);
    }
}
