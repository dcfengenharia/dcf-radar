<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoEngenhariaReprogramacao extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'documento_engenharia_reprogramacoes';

    protected $fillable = [
        'tenant_id',
        'documento_engenharia_id',
        'data_anterior',
        'data_nova',
        'criado_por_id',
    ];

    protected $casts = [
        'data_anterior' => 'date',
        'data_nova' => 'date',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoEngenharia::class, 'documento_engenharia_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id');
    }
}
