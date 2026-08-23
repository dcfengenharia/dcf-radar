<?php

namespace App\Models;

use App\Enums\ResultadoRecolhimento;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 18, Etapa 18.5.1 — a matriz explícita item×destinatário: o fato
 * atômico "este destinatário recebeu este item nesta GRD, nesta
 * quantidade". `quantidade` é a quantidade ENTREGUE (nunca reescrita
 * depois da emissão) — o que foi de fato recolhido é sempre derivado dos
 * eventos em `recolhimentos()` (App\Models\GrdRecolhimento), nunca um
 * campo próprio nesta linha.
 */
class GrdDistribuicao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'grd_distribuicoes';

    protected $fillable = [
        'tenant_id',
        'grd_item_id',
        'grd_destinatario_id',
        'quantidade',
    ];

    protected $casts = [
        'quantidade' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(GrdItem::class, 'grd_item_id');
    }

    public function grdDestinatario(): BelongsTo
    {
        return $this->belongsTo(GrdDestinatario::class, 'grd_destinatario_id');
    }

    public function recolhimentos(): HasMany
    {
        return $this->hasMany(GrdRecolhimento::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function quantidadeEntregue(): int
    {
        return $this->quantidade;
    }

    /**
     * Soma de todos os eventos de resultado=Recolhido — nao_localizado
     * NUNCA entra nesta soma (regra de domínio central da 18.5.1: uma
     * tentativa sem sucesso não reduz pendência).
     */
    public function quantidadeRecolhida(): int
    {
        return $this->relationLoaded('recolhimentos')
            ? $this->recolhimentos
                ->where('resultado', ResultadoRecolhimento::Recolhido)
                ->sum('quantidade')
            : (int) $this->recolhimentos()
                ->where('resultado', ResultadoRecolhimento::Recolhido->value)
                ->sum('quantidade');
    }

    public function quantidadePendente(): int
    {
        return max($this->quantidadeEntregue() - $this->quantidadeRecolhida(), 0);
    }

    /**
     * Estado DERIVADO (nunca persistido — seção 9 do briefing 18.5.1):
     * - pendente == 0                              -> 'recolhido'
     * - pendente > 0 e o ÚLTIMO evento (created_at
     *   DESC, id DESC — mesmo critério canônico de
     *   "último evento" já usado por
     *   RevisaoLiberacao::ultimaLiberacao() em todo
     *   o projeto) é nao_localizado                -> 'nao_localizado'
     * - qualquer outro caso (sem evento, ou último
     *   evento é um recolhimento parcial)           -> 'pendente'
     *
     * Retorna string crua ('pendente'|'nao_localizado'|'recolhido'),
     * deliberadamente sem um enum novo — instrução explícita do usuário
     * de não criar enums além de StatusGrd/ResultadoRecolhimento nesta
     * fase.
     *
     * **18.5.1.HARDENING — semântica de "último evento", congelada e não
     * alterável sem etapa própria de produto**: `ocorrido_em` é a data
     * INFORMADA do fato físico (pode ser digitada retroativamente);
     * `created_at`/`id` é a ordem de REGISTRO do evento no sistema. O
     * "estado operacional atual" desta distribuição segue a ordem de
     * REGISTRO, nunca `ocorrido_em` — deliberadamente consistente com
     * `RevisaoLiberacao::ultimaLiberacao()` (Ciclo 18, 18.3), que já
     * documenta o mesmo raciocínio: usar a data informada pelo usuário
     * pra decidir o estado atual abriria brecha pra um lançamento
     * retroativo reescrever silenciosamente o presente. Provado
     * empiricamente por teste dedicado (evento mais antigo por
     * `ocorrido_em` mas mais recente por `created_at` vence).
     */
    public function estado(): string
    {
        if ($this->quantidadePendente() === 0) {
            return 'recolhido';
        }

        $ultimoEvento = $this->relationLoaded('recolhimentos')
            ? $this->recolhimentos->first()
            : $this->recolhimentos()->first();

        if ($ultimoEvento !== null && $ultimoEvento->resultado === ResultadoRecolhimento::NaoLocalizado) {
            return 'nao_localizado';
        }

        return 'pendente';
    }
}
