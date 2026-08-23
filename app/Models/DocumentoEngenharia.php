<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentoEngenharia extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'documentos_engenharia';

    /**
     * status_documento_id NÃO é mais escrito/lido como fonte de verdade —
     * status agora é da emissão (DocumentoEngenhariaRevisao), nunca do
     * documento. Ver statusAtual(). A coluna fica órfã no banco (não foi
     * dropada, só parou de ser usada) até uma limpeza futura.
     */
    protected $fillable = [
        'tenant_id',
        'obra_id',
        'pacote_engenharia_id',
        'codigo',
        'descricao',
        'disciplina_id',
        'data_planejada',
        'data_realizada',
        'ordem',
    ];

    protected $casts = [
        'data_planejada' => 'date',
        'data_realizada' => 'date',
        'ordem' => 'integer',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(PacoteEngenharia::class, 'pacote_engenharia_id');
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function revisoes(): HasMany
    {
        return $this->hasMany(DocumentoEngenhariaRevisao::class, 'documento_engenharia_id')
            ->ordenadasPorVigencia();
    }

    public function revisaoAtual(): ?DocumentoEngenhariaRevisao
    {
        return $this->revisoes->first();
    }

    /**
     * Ciclo 18, Etapa 18.3.CORREÇÃO — a revisão "mais recente" pra fins de
     * status atual/vigência AGORA É a mesma ordem canônica documental de
     * `revisoes()` (`DocumentoEngenhariaRevisao::scopeOrdenadasPorVigencia()`
     * — data_emissao DESC, empate por created_at DESC, empate final por
     * id DESC), não mais "última linha criada". Auditoria da 18.3
     * (NÃO APROVAR) provou empiricamente que ordenar só por created_at
     * permitia uma revisão cadastrada retroativamente (data_emissao
     * antiga, mas criada depois na plataforma) virar "vigente" e derrubar
     * silenciosamente uma liberação para construção já concedida — sem
     * nenhuma ação humana sobre a liberação em si.
     *
     * DELIBERADAMENTE NÃO usa `ofMany()` — prova empírica (mesma
     * auditoria) confirmou que `ofMany(['data_emissao' => 'max', ...])`
     * retorna NENHUM resultado quando TODAS as revisões de um documento
     * têm `data_emissao` NULL (o MAX/MIN agregado de um grupo 100% NULL é
     * NULL, e a junção de volta `WHERE data_emissao = NULL` nunca casa em
     * SQL) — cenário comum na prática real deste tenant, cuja planilha de
     * origem normalmente chega sem a coluna de data de emissão preenchida
     * (ver docblock de DocumentoEngenhariaImporter). Em vez disso, é um
     * HasOne comum com `orderByDesc` encadeado — mesmo mecanismo já usado
     * por `revisoes()->first()`, que a própria auditoria confirmou lidar
     * corretamente com grupos 100% NULL (MySQL ordena NULL por último em
     * DESC, testado com uma query real antes desta correção). Eloquent
     * consegue elegir esse HasOne em massa via `with('latestRevisao')`
     * sem N+1: a query única `WHERE documento_engenharia_id IN (...)
     * ORDER BY data_emissao DESC, created_at DESC, id DESC` retorna as
     * linhas de TODOS os documentos já na ordem correta, e o `match()` do
     * HasOne usa a PRIMEIRA linha encontrada por documento (mesmo padrão
     * usado por qualquer "latest of many" via HasOne antes do `ofMany`
     * existir) — coberto por teste dedicado de N+1 e de correção com
     * grupo 100% NULL.
     */
    public function latestRevisao(): HasOne
    {
        return $this->hasOne(DocumentoEngenhariaRevisao::class, 'documento_engenharia_id')
            ->ordenadasPorVigencia();
    }

    /**
     * A 1ª emissão registrada (mais antiga por ordem de criação, não por
     * data_emissao) — representa a "emissão real" do documento, imutável:
     * novas revisões depois dela nunca mudam qual foi a primeira. HasOne
     * (ofMany) pelo mesmo motivo de latestRevisao(): eager-load em massa
     * pro dashboard sem N+1.
     */
    public function primeiraRevisao(): HasOne
    {
        return $this->hasOne(DocumentoEngenhariaRevisao::class, 'documento_engenharia_id')
            ->ofMany(['created_at' => 'min', 'id' => 'min']);
    }

    public function reprogramacoes(): HasMany
    {
        return $this->hasMany(DocumentoEngenhariaReprogramacao::class, 'documento_engenharia_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function itensSuprimento(): BelongsToMany
    {
        return $this->belongsToMany(ItemSuprimento::class, 'item_suprimento_documentos')
            ->using(ItemSuprimentoDocumento::class);
    }

    /**
     * Ciclo 18, Etapa 18.1 — Atividades do cronograma que dependem deste
     * documento. Chave é sempre atividade_id (nunca WBS/codigo_cronograma),
     * então o vínculo sobrevive a reimportação/mudança de WBS enquanto a
     * Atividade preservar sua PK (reconciliação por external_uid).
     */
    public function atividades(): BelongsToMany
    {
        return $this->belongsToMany(Atividade::class, 'documento_engenharia_atividades')
            ->using(DocumentoEngenhariaAtividade::class);
    }

    /**
     * Status "de verdade" do documento — o da emissão mais recente. Enquanto
     * não houver nenhuma emissão, não existe status (null = "Não Emitido" na
     * UI, não é uma linha do catálogo StatusDocumento).
     */
    public function statusAtual(): ?StatusDocumento
    {
        return $this->latestRevisao?->statusDocumento;
    }

    public function estaEmitido(): bool
    {
        return $this->latestRevisao !== null;
    }

    public function primeiraEmissao(): ?DocumentoEngenhariaRevisao
    {
        return $this->primeiraRevisao;
    }

    public function dataEmissaoReal(): ?Carbon
    {
        return $this->primeiraRevisao?->data_emissao;
    }

    public function estaAtrasado(): bool
    {
        return !$this->estaEmitido() && (bool) $this->data_planejada?->isPast();
    }

    /**
     * Ciclo 18, Etapa 18.3.CORREÇÃO — ÚNICA definição de "revisão vigente"
     * de todo o projeto, reaproveitada por statusAtual()/estaEmitido()/
     * estaLiberadoParaConstrucao()/motivoLiberacao()/Lista Mestra/modal/
     * badges/botões/importador/dashboard/curvas (ver mapa de consumidores
     * no relatório da 18.3.CORREÇÃO) — nunca uma segunda regra concorrente.
     * Sempre a mesma relação `latestRevisao` (ordem canônica documental).
     *
     * Usa `relationLoaded()` antes de tocar a propriedade mágica —
     * quando o chamador já fez eager-load (`with('latestRevisao')`,
     * caminho de toda tela), reaproveita o resultado já carregado sem
     * nenhuma query extra; quando não (ex.: DocumentoEngenhariaImporter,
     * que nunca eager-carrega o Documento antes de chamar isto), roda uma
     * query explícita e fresca — nunca dispara
     * LazyLoadingViolationException (Model::preventLazyLoading está
     * ativo fora de produção) por acessar a propriedade mágica sem
     * eager-load prévio.
     */
    public function revisaoVigente(): ?DocumentoEngenhariaRevisao
    {
        return $this->relationLoaded('latestRevisao')
            ? $this->getRelation('latestRevisao')
            : $this->latestRevisao()->first();
    }

    /**
     * Contrato canônico pra Etapa 18.4 (Restrição GED, ainda não
     * implementada) — deriva SEMPRE da revisão vigente, nunca de um
     * campo solto no Documento. R1 liberada + R2 nova não-liberada =
     * Documento NÃO liberado (a liberação de R1 continua existindo no
     * histórico dela, só não controla mais o Documento).
     */
    public function estaLiberadoParaConstrucao(): bool
    {
        return (bool) $this->revisaoVigente()?->estaLiberadaParaConstrucao();
    }

    /**
     * "Por quê" a liberação está ou não ativa, representável em código —
     * nunca texto solto espalhado pela UI. Só 3 estados hoje (nenhuma
     * revisão / revisão vigente liberada / revisão vigente não liberada)
     * porque não existe no projeto uma taxonomia documental mais rica
     * ("em análise", "aprovada com comentários" etc.) — decisão
     * deliberada de não inventar uma agora (ver relatório da Etapa 18.3).
     */
    public function motivoLiberacao(): string
    {
        $revisao = $this->revisaoVigente();

        if ($revisao === null) {
            return 'sem_revisao';
        }

        return $revisao->estaLiberadaParaConstrucao() ? 'revisao_liberada' : 'revisao_nao_liberada';
    }

    /**
     * Ciclo 18, Etapa 18.4.CORREÇÃO — versão SQL-only (sem carregar
     * nenhum model) de `! estaLiberadoParaConstrucao()`, pra uso dentro de
     * `Atividade::scopeProntas()`/`whereDoesntHave('documentosEngenharia',
     * ...)` sem N+1. Mesma regra de 3 estados de `motivoLiberacao()`,
     * nunca uma quarta definição:
     * - sem nenhuma revisão -> bloqueia (`whereDoesntHave('revisoes')`);
     * - revisão vigente (`scopeVigentes()`, mesmo critério de
     *   `latestRevisao()`) sem NENHUM evento de liberação -> bloqueia;
     * - revisão vigente cujo ÚLTIMO evento (`scopeUltimoEvento()`, mesmo
     *   critério de `ultimaLiberacao()`) é `liberada_para_construcao =
     *   false` -> bloqueia.
     * Documento com revisão vigente cujo último evento é `true` NUNCA
     * entra aqui (é o único caso não coberto pelas 3 condições acima).
     */
    public function scopeNaoLiberados(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereDoesntHave('revisoes')
                ->orWhereHas('revisoes', function (Builder $r) {
                    $r->vigentes()->where(function (Builder $rr) {
                        $rr->whereDoesntHave('historicoLiberacoes')
                            ->orWhereHas('historicoLiberacoes', function (Builder $h) {
                                $h->ultimoEvento()->where('liberada_para_construcao', false);
                            });
                    });
                });
        });
    }
}
