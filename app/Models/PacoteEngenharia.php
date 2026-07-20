<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PacoteEngenharia extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'pacotes_engenharia';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'nome',
        'descricao',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoEngenharia::class)->orderBy('ordem')->with('latestRevisao.statusDocumento');
    }

    public function percentualConcluido(): float
    {
        $total = $this->documentos->count();

        if ($total === 0) {
            return 0.0;
        }

        $concluidos = $this->documentos
            ->filter(fn(DocumentoEngenharia $d) => (bool) $d->statusAtual()?->conclusivo)
            ->count();

        return round(($concluidos / $total) * 100, 2);
    }

    public function proximaDataLimite(): ?Carbon
    {
        return $this->documentos
            ->reject(fn(DocumentoEngenharia $d) => (bool) $d->statusAtual()?->conclusivo)
            ->pluck('data_planejada')
            ->filter()
            ->max();
    }
}
