<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramacaoSemanal extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'programacoes_semanais';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'semana_inicio',
        'semana_fim',
        'congelada_em',
        'criado_por',
    ];

    protected $casts = [
        'semana_inicio' => 'date',
        'semana_fim' => 'date',
        'congelada_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ProgramacaoSemanalItem::class);
    }
}
