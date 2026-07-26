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
        'hh_realizado',
        'realizado_por',
        'realizado_em',
    ];

    protected $casts = [
        'inicio_planejado_congelado' => 'date',
        'data_termino_congelado' => 'date',
        'horas_previstas_congeladas' => 'decimal:2',
        'origem' => OrigemProgramacaoSemanalItem::class,
        'hh_realizado' => 'decimal:2',
        'realizado_em' => 'datetime',
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

    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizado_por');
    }

    /**
     * % Realizado = HH Realizado (lançado manualmente pelo usuário nesta
     * programação) ÷ HH Total da Atividade (Atividade.work_horas — HH da
     * Tendência/Work atual do MS Project, mesmo conceito já usado em
     * "% da Tendência" no Plano Semanal; decisão do usuário, 2026-07-26).
     * `null` sem HH realizado lançado ainda, ou sem work_horas cadastrado
     * (evita divisão por zero) — nunca zero, mesmo idioma de "traço" já
     * usado no resto do sistema pra "sem dado".
     */
    public function percentualRealizado(): ?float
    {
        if ($this->hh_realizado === null) {
            return null;
        }

        $hhTotal = (float) ($this->atividade?->work_horas ?? 0);
        if ($hhTotal <= 0) {
            return null;
        }

        return round(((float) $this->hh_realizado / $hhTotal) * 100, 2);
    }
}
