<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 20, Etapa 20.6 — evento FÍSICO append-only de transferência de
 * material entre dois Locais PRÓPRIOS da mesma obra. Ver docblock da
 * migration pra fundamentos completos (identidade de operação, nunca
 * envolve Local Terceiro).
 */
class TransferenciaEstoque extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'transferencias_estoque';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'material_id',
        'unidade_estoque_id',
        'local_origem_id',
        'local_destino_id',
        'quantidade',
        'ocorrido_em',
        'movimentacao_saida_id',
        'movimentacao_entrada_id',
        'registrado_por',
        'observacao',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
        'ocorrido_em' => 'date',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function unidadeEstoque(): BelongsTo
    {
        return $this->belongsTo(UnidadeEstoque::class);
    }

    public function localOrigem(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class, 'local_origem_id');
    }

    public function localDestino(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class, 'local_destino_id');
    }

    public function movimentacaoSaida(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class, 'movimentacao_saida_id');
    }

    public function movimentacaoEntrada(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class, 'movimentacao_entrada_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
