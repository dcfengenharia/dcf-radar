<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DocumentoEngenhariaRevisao extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'documento_engenharia_revisoes';

    protected $fillable = [
        'tenant_id',
        'documento_engenharia_id',
        'status_documento_id',
        'revisao',
        'data_emissao',
        'descricao',
        'comentarios',
        'anexo_path',
        'anexo_nome_original',
        'criado_por_id',
    ];

    protected $casts = [
        'data_emissao' => 'date',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoEngenharia::class, 'documento_engenharia_id');
    }

    public function statusDocumento(): BelongsTo
    {
        return $this->belongsTo(StatusDocumento::class, 'status_documento_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id');
    }

    public function anexoUrl(): ?string
    {
        return $this->anexo_path && Storage::disk('public')->exists($this->anexo_path)
            ? Storage::disk('public')->url($this->anexo_path)
            : null;
    }
}
