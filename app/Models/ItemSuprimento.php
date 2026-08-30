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

/**
 * Ciclo 19, Etapa 19.3 — este model evolui conceitualmente pra "Pacote de
 * Compra": a UI passa a chamá-lo assim, mas a tabela/model/PK/ULID
 * permanecem os mesmos (decisão do produto, confirmada por investigação —
 * `ItemSuprimento` já não carrega quantidade/unidade própria e já é N:N
 * com Atividade/Documento, ou seja, já era estruturalmente um coordenador
 * de workflow de compra, nunca uma linha de material individual). Nenhum
 * rename destrutivo de tabela/model.
 */
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

    /** Ciclo 19, Etapa 19.3 — alocações de RequisicaoPlanejamentoItem recebidas por este Pacote. */
    public function alocacoes(): HasMany
    {
        return $this->hasMany(AlocacaoRequisicaoPacote::class, 'item_suprimento_id');
    }

    /**
     * Ciclo 19, Etapa 19.4 — Requisições de Compra formais deste Pacote
     * (domínio novo e paralelo ao mecanismo legado de `etapas()`/
     * `status` acima — os dois nunca se sincronizam).
     */
    public function requisicoesCompra(): HasMany
    {
        return $this->hasMany(RequisicaoCompra::class, 'item_suprimento_id');
    }

    /**
     * Data de necessidade do item: a mais cedo entre as atividades
     * vinculadas ATIVAS — é a atividade que "primeiro precisa" do
     * material que dirige o agendamento retroativo
     * (App\Services\SuprimentoScheduler), não uma atividade específica.
     *
     * Ciclo 19, Etapa 19.3 — correção do risco R1 registrado na
     * investigação 19.0: atividade `fora_do_cronograma = true` (arquivada
     * numa reimportação) NUNCA participa do cálculo — mesmo critério já
     * usado em toda regra de "atividade ativa" do projeto (Fotografia O,
     * Health Check, Plano de Ação). O vínculo em `item_suprimento_atividades`
     * NUNCA é removido automaticamente por isso — só deixa de contar pra
     * essa data específica; se a atividade for reativada numa reimportação
     * seguinte, volta a contar sozinha, sem nenhuma ação manual.
     */
    public function necessidade(): ?Carbon
    {
        return $this->atividades
            ->reject(fn (Atividade $atividade) => $atividade->fora_do_cronograma)
            ->min('inicio_planejado');
    }

    /**
     * Ciclo 19, Etapa 19.5 — "data projetada de atendimento" do Pacote
     * (decisão do usuário): o MÁXIMO entre `RequisicaoCompra::
     * dataProjetadaAtendimento()` de todas as RCs formais (Emitida/
     * Concluida — Rascunho nunca representa demanda formal) deste
     * Pacote. Cada RC já resolve, por si, "Pedido Emitido mais tarde,
     * ou fimPrevisto() como fallback" — aqui só agregamos o pior caso
     * entre as RCs, nunca somando/misturando unidades.
     */
    public function dataProjetadaAtendimento(): ?Carbon
    {
        return $this->requisicoesCompra
            ->reject(fn (RequisicaoCompra $rc) => $rc->status === \App\Enums\StatusRequisicaoCompra::Rascunho)
            ->map(fn (RequisicaoCompra $rc) => $rc->dataProjetadaAtendimento())
            ->filter()
            ->max();
    }

    /**
     * "Risco de atendimento"/"Folga até necessidade" — NUNCA "impacto no
     * cronograma" (terminologia explícita do produto). folga > 0 = há
     * margem; folga = 0 = atendimento exatamente na necessidade;
     * folga < 0 = risco (a previsão ultrapassa a necessidade do
     * cronograma). `null` sempre que faltar um dos dois lados — nunca
     * inventa 0 nem erro. Nunca cria Restricao automaticamente aqui.
     */
    public function folgaAtendimento(): ?int
    {
        $necessidade = $this->necessidade();
        $atendimento = $this->dataProjetadaAtendimento();

        if (! $necessidade || ! $atendimento) {
            return null;
        }

        return (int) $atendimento->diffInDays($necessidade, false);
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
