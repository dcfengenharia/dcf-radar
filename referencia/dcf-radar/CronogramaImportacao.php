<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registro de cada importação de cronograma (snapshot + auditoria).
 * Guarda a data de status e o método de distribuição usado nas curvas
 * (transparência sobre como os HH por período foram reconstruídos).
 */
class CronogramaImportacao extends Model
{
    use HasUlids, BelongsToTenant;

    protected $table = 'cronograma_importacoes';

    protected $fillable = [
        'tenant_id', 'obra_id', 'user_id', 'arquivo', 'data_status',
        'metodo_distribuicao', 'criadas', 'atualizadas', 'removidas', 'importado_em',
    ];

    protected function casts(): array
    {
        return [
            'data_status' => 'date',
            'criadas' => 'integer',
            'atualizadas' => 'integer',
            'removidas' => 'integer',
            'importado_em' => 'datetime',
        ];
    }

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Obra::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function avancoPeriodos(): HasMany
    {
        return $this->hasMany(AvancoPeriodo::class);
    }
}
