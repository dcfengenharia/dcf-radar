<?php

namespace App\Models;

use App\Enums\StatusItemSuprimento;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItemSuprimento extends Model
{
    use BelongsToTenant, HasAuthorship, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'itens_suprimento';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'fluxo_suprimento_id',
        'fornecedor_id',
        'responsavel_id',
        'created_by_id',
        'nome',
        'codigo',
        'status',
        'observacoes',
        'alerta_21d_enviado_em',
        'alerta_10d_enviado_em',
    ];

    protected $casts = [
        'status' => StatusItemSuprimento::class,
        'alerta_21d_enviado_em' => 'datetime',
        'alerta_10d_enviado_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function fluxo(): BelongsTo
    {
        return $this->belongsTo(FluxoSuprimento::class, 'fluxo_suprimento_id');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function atividades(): BelongsToMany
    {
        return $this->belongsToMany(Atividade::class, 'item_suprimento_atividades')
            ->using(ItemSuprimentoAtividade::class);
    }

    public function documentosEngenharia(): BelongsToMany
    {
        return $this->belongsToMany(DocumentoEngenharia::class, 'item_suprimento_documentos')
            ->using(ItemSuprimentoDocumento::class)
            ->with('latestRevisao.statusDocumento');
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(ItemSuprimentoEtapa::class)->orderBy('ordem');
    }

    public function restricoesOrigem(): HasMany
    {
        return $this->hasMany(Restricao::class, 'origem_suprimento_item_id');
    }

    public function comentarios(): HasMany
    {
        return $this->hasMany(ItemSuprimentoComentario::class, 'item_suprimento_id')->latest();
    }

    /**
     * Data de necessidade do item: a mais cedo entre TODAS as atividades
     * vinculadas — é a atividade que "primeiro precisa" do material que
     * dirige o agendamento retroativo (App\Services\SuprimentoScheduler),
     * não uma atividade específica.
     */
    public function necessidade(): ?Carbon
    {
        return $this->atividades->min('inicio_planejado');
    }

    /**
     * Prontidão de engenharia do item — informativo, não bloqueia o
     * cálculo de datas do SuprimentoScheduler. Calculado sobre
     * $this->documentosEngenharia, que pode vir de mais de um
     * PacoteEngenharia diferente (o vínculo real é no documento, o
     * pacote é só um atalho de UI pra achar documentos mais rápido).
     */
    public function percentualEngenhariaConcluida(): float
    {
        $total = $this->documentosEngenharia->count();

        if ($total === 0) {
            return 0.0;
        }

        $concluidos = $this->documentosEngenharia->filter(fn(DocumentoEngenharia $d) => (bool) $d->statusAtual()?->conclusivo)->count();

        return round(($concluidos / $total) * 100, 2);
    }

    public function proximaDataLimiteEngenharia(): ?Carbon
    {
        return $this->documentosEngenharia
            ->reject(fn(DocumentoEngenharia $d) => (bool) $d->statusAtual()?->conclusivo)
            ->pluck('data_planejada')
            ->filter()
            ->max();
    }
}
