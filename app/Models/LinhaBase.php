<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LinhaBase extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $table = 'linhas_base';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'nome',
        'descricao',
        'cronograma_importacao_id',
        'criado_por',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'cronograma_importacao_id');
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
