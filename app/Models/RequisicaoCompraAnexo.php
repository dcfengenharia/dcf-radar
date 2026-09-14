<?php

namespace App\Models;

use App\Enums\TipoDocumentoRequisicaoCompra;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Etapa 2 — dossiê documental de uma RC (Proposta/Contrato/Parecer/
 * Mapa Comparativo/Correspondência/Outro). Storage PRIVADO (mesmo
 * padrão de `AtividadeAnexo`/`AnexarRevisaoDocumento`, Ciclo 17/18) —
 * nunca `Storage::url()`/link direto, download sempre via
 * `App\Http\Controllers\RequisicaoCompraAnexoController::download()`.
 *
 * Metadados de Proposta (Seção 17, V1 leve) vivem aqui mesmo — sempre
 * nullable, sem workflow de cotação/comparação automática.
 */
class RequisicaoCompraAnexo extends Model
{
    use BelongsToTenant, HasUlids;

    /** Mesmo disco/limite de `AnexarRevisaoDocumento`/`AtividadeAnexo`. */
    public const DISCO = 'local';

    public const TAMANHO_MAXIMO_KB = 10240;

    /** Mimes aceitos (documento de processo de compra — nunca executável/script). */
    public const MIMES_ACEITOS = 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png';

    protected $table = 'requisicao_compra_anexos';

    protected $fillable = [
        'tenant_id',
        'requisicao_compra_id',
        'tipo_documento',
        'fornecedor_id',
        'nome_original',
        'caminho_arquivo',
        'mime_type',
        'tamanho_bytes',
        'descricao',
        'enviado_por_id',
        'substitui_anexo_id',
        'valor_total_referencia',
        'prazo_referencia',
        'validade_ate',
    ];

    protected $casts = [
        'tipo_documento' => TipoDocumentoRequisicaoCompra::class,
        'tamanho_bytes' => 'integer',
        'valor_total_referencia' => 'decimal:2',
        'validade_ate' => 'date',
    ];

    public function requisicaoCompra(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompra::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por_id');
    }

    public function anexoAnterior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'substitui_anexo_id');
    }

    public function versoesPosteriores(): HasMany
    {
        return $this->hasMany(self::class, 'substitui_anexo_id');
    }

    /** Adjudicações que citam este anexo como evidência de suporte da decisão (Seção 19). */
    public function adjudicacoesSuportadas(): HasMany
    {
        return $this->hasMany(RequisicaoCompraAdjudicacao::class, 'anexo_id');
    }
}
