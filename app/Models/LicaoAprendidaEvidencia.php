<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 23, Etapa 23.2 — evidência/anexo de uma lição. Espelha
 * `App\Models\AtividadeAnexo` (disco privado, nunca público — resolução
 * real do arquivo sempre via a Action/Controller dedicados, nunca lida
 * diretamente por Storage::url() em nenhum ponto do projeto).
 */
class LicaoAprendidaEvidencia extends Model
{
    use BelongsToTenant, HasUlids;

    public const DISCO = 'local';

    public const TAMANHO_MAXIMO_KB = 10240;

    protected $table = 'licao_aprendida_evidencias';

    protected $fillable = [
        'tenant_id',
        'licao_aprendida_id',
        'nome_original',
        'caminho_arquivo',
        'mime_type',
        'tamanho_bytes',
        'enviado_por',
    ];

    public function licao(): BelongsTo
    {
        return $this->belongsTo(LicaoAprendida::class, 'licao_aprendida_id');
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
