<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\Unit;
use App\Models\User;
use App\Services\ApprovalNotifier;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsCampus;
use Tests\TestCase;

/** Unit induk tidak boleh melingkar: fakultas → prodi → laboratorium. */
class UnitHierarchyTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    private Unit $faculty;

    private Unit $prodi;

    private Unit $lab;

    private Unit $other;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate(User::ROLE_ADMIN, 'web');
        $this->buildCampus();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $make = fn (string $name, ?Unit $parent = null) => Unit::create([
            'institution_id' => $this->inst->id, 'parent_id' => $parent?->id, 'name' => $name, 'type' => UnitType::Lembaga,
        ]);
        $this->faculty = $make('Fakultas Uji');
        $this->prodi = $make('Prodi Uji', $this->faculty);
        $this->lab = $make('Laboratorium Uji', $this->prodi);
        $this->other = $make('Lembaga Lain');
    }

    private function actAsAdmin(): void
    {
        $admin = $this->makeEmployee()->user;
        $admin->assignRole(User::ROLE_ADMIN);
        $this->actingAs($admin);
    }

    public function test_unit_cannot_be_its_own_parent(): void
    {
        $this->expectException(ValidationException::class);

        $this->faculty->update(['parent_id' => $this->faculty->id]);
    }

    public function test_unit_cannot_move_under_its_descendant(): void
    {
        $this->expectException(ValidationException::class);

        $this->faculty->update(['parent_id' => (string) $this->lab->id]); // form mengirim string
    }

    public function test_unit_can_move_under_another_branch(): void
    {
        $this->prodi->update(['parent_id' => $this->other->id]);

        $this->assertSame($this->other->id, $this->prodi->fresh()->parent_id);
        $this->assertSame([$this->other->id, $this->prodi->id, $this->lab->id], $this->other->descendantIds());
    }

    public function test_edit_form_does_not_offer_own_descendants(): void
    {
        $this->actAsAdmin();

        Livewire::test(ManageUnits::class)
            ->mountAction(TestAction::make('edit')->table($this->faculty))
            ->assertMountedActionModalSee('Lembaga Lain')
            ->assertMountedActionModalDontSee('Laboratorium Uji');
    }

    public function test_tampered_form_cannot_create_a_loop(): void
    {
        $this->actAsAdmin();

        Livewire::test(ManageUnits::class)
            ->callAction(TestAction::make('edit')->table($this->faculty), data: ['parent_id' => $this->lab->id]);

        $this->assertNull($this->faculty->fresh()->parent_id);
    }

    public function test_unit_head_is_chosen_by_employee_name(): void
    {
        $this->actAsAdmin();
        $head = $this->makeEmployee();
        $head->user->update(['name' => 'Calon Kepala Unit']);

        Livewire::test(ManageUnits::class)
            ->mountAction(TestAction::make('edit')->table($this->prodi))
            ->assertMountedActionModalSee('Calon Kepala Unit');
        Livewire::test(ManageUnits::class)
            ->callAction(TestAction::make('edit')->table($this->prodi), data: ['head_employee_id' => $head->id])
            ->assertHasNoFormErrors();

        $this->assertSame($head->id, $this->prodi->fresh()->head_employee_id);
    }

    public function test_existing_loop_does_not_hang_and_can_be_repaired(): void
    {
        DB::table('units')->where('id', $this->faculty->id)->update(['parent_id' => $this->lab->id]);
        $employee = $this->makeEmployee(['unit_id' => $this->lab->id]);

        $this->assertEqualsCanonicalizing([$this->faculty->id, $this->prodi->id, $this->lab->id], $this->faculty->fresh()->descendantIds());
        $this->assertCount(0, app(ApprovalNotifier::class)->approversFor($employee));

        $this->faculty->fresh()->update(['name' => 'Fakultas Baru']);
        $this->faculty->fresh()->update(['parent_id' => null]);
        $this->assertNull($this->faculty->fresh()->parent_id);
    }
}
