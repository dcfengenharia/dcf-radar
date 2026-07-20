<?php

namespace App\Models;

use App\Enums\OrigemProgramacaoSemanalItem;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramacaoSemanalItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'programacao_semanal_itens';

    protected $fillable = [
        'tenant_id',
        'programacao_semanal_id',
        'atividade_id',
        'inicio_planejado_congelado',
        'data_termino_congelado',
        'horas_previstas_congeladas',
        'origem',
        'criado_por',
    ];

    protected $casts = [
        'inicio_planejado_congelado' => 'date',
        'data_termino_congelado' => 'date',
        'horas_previstas_congeladas' => 'decimal:2',
        'origem' => OrigemProgramacaoSemanalItem::class,
    ];

    public function programacaoSemanal(): BelongsTo
    {
        return $this->belongsTo(ProgramacaoSemanal::class);
    }

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
