<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StatusDocumento extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'status_documentos_engenharia';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'nome',
        'codigo',
        'cor',
        'ordem',
        'conclusivo',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'conclusivo' => 'boolean',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoEngenharia::class, 'status_documento_id');
    }
}
