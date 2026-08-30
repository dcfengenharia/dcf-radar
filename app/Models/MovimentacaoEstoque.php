<?php

namespace App\Models;

use App\Enums\TipoMovimentacaoEstoque;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 20, Etapa 20.1 — MovimentacaoEstoque: ledger append-only do fato
 * físico de estoque. Fonte única de verdade pra todo saldo (nunca
 * persistido redundante em nenhum outro lugar) — App\Support\Estoque\
 * SaldoEstoque agrega esta tabela sob demanda.
 *
 * Imutabilidade real (App\Observers\MovimentacaoEstoqueObserver bloqueia
 * updating()/deleting() incondicionalmente) — só
 * App\Actions\Estoque\RegistrarEntradaEstoque/RegistrarSaidaEstoque
 * escrevem aqui. Query Builder cru/mass update permanecem API PROIBIDA
 * por convenção arquitetural (mesma limitação estrutural já documentada
 * e aceita em App\Models\RecebimentoPedido desde o Ciclo 19, 19.6.CORREÇÃO).
 *
 * Ciclo 20, Etapa 20.3 — Saída física adicionada. `MovimentacaoEstoque`
 * continua sendo a ÚNICA entidade de fato físico (Entrada e Saída) —
 * `App\Enums\TipoMovimentacaoEstoque` cresce sem migration (coluna
 * string, não MySQL ENUM físico). Campos novos, todos nullable e só
 * populados por uma Saída: `reserva_estoque_id` (no máximo 1 Reserva
 * consumida por Saída — FK simples, decisão do usuário), `item_suprimento_id`
 * (Pacote — derivado da Reserva quando ela existe, opcional sem
 * Reserva, nunca inventado), `frente_trabalho_id` (destino informado
 * NAQUELE INSTANTE pelo operador — nunca a aplicação final conciliada,
 * que é responsabilidade da 20.4), `retirado_por`/`retirado_por_externo`
 * (quem fisicamente retirou — usuário cadastrado OU pessoa externa,
 * mesmo par já usado em Restricao.responsavel_id/responsavel_externo;
 * nunca confundir com `registrado_por`, que continua sendo só "quem
 * lançou no sistema").
 */
class MovimentacaoEstoque extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'movimentacoes_estoque';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'tipo',
        'material_id',
        'local_estoque_id',
        'unidade_estoque_id',
        'reserva_estoque_id',
        'recebimento_pedido_id',
        'item_take_off_id',
        'item_suprimento_id',
        'frente_trabalho_id',
        'quantidade',
        'ocorrido_em',
        'registrado_por',
        'retirado_por',
        'retirado_por_externo',
        'observacao',
    ];

    protected $casts = [
        'tipo' => TipoMovimentacaoEstoque::class,
        'quantidade' => 'decimal:3',
        'ocorrido_em' => 'date',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
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

    public function reservaEstoque(): BelongsTo
    {
        return $this->belongsTo(ReservaEstoque::class);
    }

    public function recebimentoPedido(): BelongsTo
    {
        return $this->belongsTo(RecebimentoPedido::class);
    }

    public function itemTakeOff(): BelongsTo
    {
        return $this->belongsTo(ItemTakeOff::class);
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function frenteTrabalho(): BelongsTo
    {
        return $this->belongsTo(FrenteTrabalho::class, 'frente_trabalho_id')->withTrashed();
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function retiradoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retirado_por');
    }
}
