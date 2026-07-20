<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feriado extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'feriados';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'data',
        'descricao',
    ];

    protected $casts = [
        'data' => 'date',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }
}
