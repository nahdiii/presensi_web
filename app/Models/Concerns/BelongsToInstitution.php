<?php

namespace App\Models\Concerns;

use App\Models\Institution;

/**
 * Mengisi institution_id otomatis. Versi kampus baru satu institusi; kolom
 * ini disiapkan untuk SaaS multi-institusi.
 */
trait BelongsToInstitution
{
    protected static function bootBelongsToInstitution(): void
    {
        static::creating(function ($model) {
            $model->institution_id ??= auth()->user()?->institution_id ?? Institution::value('id');
        });
    }
}
