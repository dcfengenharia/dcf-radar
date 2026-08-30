<?php

namespace App\Models;

use App\Enums\StatusReservaEstoque;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 20, Etapa 20.2 — comprometimento FÍSICO de parte do saldo com
 * uma finalidade. NUNCA cria MovimentacaoEstoque (Seção 15/21 da
 * investigação) — saldo físico nunca muda por reservar; só o saldo
 * DISPONÍVEL (App\Support\Estoque\SaldoReserva) reduz. Ver docblock da
 * migration para granularidade física e a semântica de
 * destinacao_planejada_material_id opcional.
 *
 * Ciclo 20, Etapa 20.2.CORREÇÃO (fecha o Achado B1 da auditoria
 * adversarial) — `item_suprimento_id` (Pacote) é obrigatório desde a
 * criação: toda Reserva sabe "para qual Pacote", mesmo quando
 * `destinacao_planejada_material_id` é `null` (Frente ainda não
 * detalhada — nunca uma Frente fake). Decisão do usuário: uma Reserva
 * genérica NÃO precisa ser inteiramente consumida por uma única Frente
 * depois — uma futura saída parcial pode atender várias Frentes a
 * partir da MESMA Reserva.
 *
 * **Imutabilidade (fecha o Achado C2)**: campos factuais (material_id,
 * local_estoque_id, unidade_estoque_id, item_suprimento_id,
 * destinacao_planejada_material_id, quantidade, tenant_id, obra_id)
 * NUNCA são reescritos após a criação — `App\Observers\
 * ReservaEstoqueObserver::updating()` bloqueia QUALQUER `save()`/
 * `update()` de instância incondicionalmente. A única transição válida
 * (`Ativa -> Liberada`) é feita por `App\Actions\Estoque\
 * LiberarReservaEstoque` via mass-update de Query Builder
 * (`ReservaEstoque::where(...)->update([...])`), que NUNCA dispara
 * eventos de model Eloquent (`updating`/`saving`) — por isso é imune ao
 * Observer, sem precisar de nenhuma exceção/flag especial nele.
 */
class ReservaEstoque extends Model
{
    use BelongsToTenant, HasAuthorship, HasUlids;

    protected $table = 'reservas_estoque';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'item_suprimento_id',
        'material_id',
        'local_estoque_id',
        'unidade_estoque_id',
        'destinacao_planejada_material_id',
        'quantidade',
        'status',
        'liberado_em',
        'liberado_por',
        'motivo_liberacao',
        'created_by_id',
        'observacao',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
        'status' => StatusReservaEstoque::class,
        'liberado_em' => 'datetime',
    ];

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function localEstoque(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class);
    }

    public function unidadeEstoque(): BelongsTo
    {
        return $this->belongsTo(UnidadeEstoque::class);
    }

    public function destinacaoPlanejada(): BelongsTo
    {
        return $this->belongsTo(DestinacaoPlanejadaMaterial::class, 'destinacao_planejada_material_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function liberadoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liberado_por');
    }

    public function estaAtiva(): bool
    {
        return $this->status === StatusReservaEstoque::Ativa;
    }
}
