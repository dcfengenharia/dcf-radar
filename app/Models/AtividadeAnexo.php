<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtividadeAnexo extends Model
{
    use BelongsToTenant, HasUlids;

    /** Ciclo 17, A.7.1 — storage PRIVADO (nunca 'public'): download exige autorização, nunca Storage::url()/link direto. */
    public const DISCO = 'local';

    /** Centraliza o limite de 10MB (mesmo precedente de DocumentoEngenhariaRevisao::mimes:pdf|max:10240) — nunca espalhar o número bruto. */
    public const TAMANHO_MAXIMO_KB = 10240;

    protected $fillable = [
        'tenant_id',
        'atividade_id',
        'nome_original',
        'caminho_arquivo',
        'mime_type',
        'tamanho_bytes',
        'enviado_por',
    ];

    protected $casts = [
        'tamanho_bytes' => 'integer',
    ];

    public function atividade(): BelongsTo
    {
        return $this->belongsTo(Atividade::class);
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
