<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CausaNaoCumprimento extends Model
{
    use BelongsToTenant, HasAuthorship, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'causas_nao_cumprimento';

    protected $fillable = [
        'tenant_id',
        'atividade_id',
        'created_by_id',
        'descricao',
    ];

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
