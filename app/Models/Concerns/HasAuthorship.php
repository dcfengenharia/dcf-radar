<?php

namespace App\Models\Concerns;

trait HasAuthorship
{
    public static function bootHasAuthorship(): void
    {
        static::creating(function ($model) {
            if (auth()->check() && empty($model->created_by_id)) {
                $model->created_by_id = auth()->id();
            }
        });
    }
}
