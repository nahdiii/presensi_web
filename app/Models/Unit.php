<?php

namespace App\Models;

use App\Enums\UnitType;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class Unit extends Model
{
    use BelongsToInstitution;

    protected function casts(): array
    {
        return ['type' => UnitType::class];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Unit::class, 'parent_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    public function workPattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Unit ini lalu induk-induknya, dari yang terdekat (prodi → fakultas). */
    public function lineage(): array
    {
        $chain = [];
        $unit = $this;
        while ($unit && count($chain) < 10) {
            $chain[] = $unit;
            $unit = $unit->parent;
        }

        return $chain;
    }

    /** ID unit ini beserta seluruh turunannya. */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier) {
            $frontier = Unit::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }
}
