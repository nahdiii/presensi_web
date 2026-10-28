<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class Institution extends Model
{
    public function defaultWorkPattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class, 'default_work_pattern_id');
    }
}
