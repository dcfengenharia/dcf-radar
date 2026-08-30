<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 20, Etapa 20.2 — quanto da demanda formal de um Material dentro
 * de um Pacote de Compra (ItemSuprimento) está planejado pra uma
 * FrenteTrabalho. Camada LÓGICA — nunca move saldo físico, nunca aponta
 * pra LocalEstoque/UnidadeEstoque. Ver docblock da migration pra origem
 * quantitativa formal e política de identidade/unicidade.
 *
 * Ciclo 20, Etapa 20.2.CORREÇÃO — fecha o Achado B2 da auditoria
 * adversarial: `frenteTrabalho()` inclui `withTrashed()` DIRETO na
 * definição da relação (não em cada call-site) — esta Destinação
 * representa HISTÓRICO/planejamento, então uma Frente arquivada depois
 * não pode fazer a referência desaparecer silenciosamente pra quem
 * esquecer de pedir `withTrashed()` manualmente. Os 2 eager-loads que já
 * faziam isso manualmente em `⚡estoque.blade.php` ficaram redundantes
 * (chamar `withTrashed()` 2x é inofensivo) e foram simplificados.
 */
class DestinacaoPlanejadaMaterial extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'destinacoes_planejadas_material';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'item_suprimento_id',
        'material_id',
        'frente_trabalho_id',
        'quantidade_planejada',
        'created_by_id',
        'observacao',
    ];

    protected $casts = [
        'quantidade_planejada' => 'decimal:3',
    ];

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function frenteTrabalho(): BelongsTo
    {
        return $this->belongsTo(FrenteTrabalho::class, 'frente_trabalho_id')->withTrashed();
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(ReservaEstoque::class, 'destinacao_planejada_material_id');
    }

    /**
     * Soma das ReservaEstoque ATIVAS vinculadas a esta Destinação —
     * único ponto de leitura desta soma, reaproveitado pelo Observer
     * (guard de redução/exclusão) e pela UI (Seção 12).
     */
    public function quantidadeReservadaAtiva(): float
    {
        return (float) $this->reservas()
            ->where('status', \App\Enums\StatusReservaEstoque::Ativa->value)
            ->sum('quantidade');
    }
}
