<?php

namespace App\Models;

use App\Enums\ModalidadeEntregaProduto;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 20, Etapa 20.5 — evento append-only de "produto saiu do
 * terceiro rumo a um destino" (retorno ao estoque da obra ou entrega
 * direta ao campo). Ver docblock da migration pra fundamentos
 * completos.
 */
class EntregaProdutoIndustrializado extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'entregas_produto_industrializado';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'produto_industrializado_id',
        'quantidade',
        'unidade_estoque_id',
        'modalidade',
        'movimentacao_saida_terceiro_id',
        'movimentacao_entrada_destino_id',
        'movimentacao_saida_campo_id',
        'frente_trabalho_id',
        'retirado_por',
        'retirado_por_externo',
        'ocorrido_em',
        'registrado_por',
        'observacao',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
        'modalidade' => ModalidadeEntregaProduto::class,
        'ocorrido_em' => 'date',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutoIndustrializado::class, 'produto_industrializado_id');
    }

    public function unidadeEstoque(): BelongsTo
    {
        return $this->belongsTo(UnidadeEstoque::class);
    }

    public function movimentacaoSaidaTerceiro(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class, 'movimentacao_saida_terceiro_id');
    }

    public function movimentacaoEntradaDestino(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class, 'movimentacao_entrada_destino_id');
    }

    public function movimentacaoSaidaCampo(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class, 'movimentacao_saida_campo_id');
    }

    public function frenteTrabalho(): BelongsTo
    {
        return $this->belongsTo(FrenteTrabalho::class, 'frente_trabalho_id')->withTrashed();
    }

    public function retiradoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retirado_por');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
