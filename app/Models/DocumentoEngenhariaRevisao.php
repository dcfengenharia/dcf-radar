<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * Ciclo 18, Etapa 18.5.1 — itens de GRD que distribuíram fisicamente
     * ESTA revisão exata (histórico de distribuição, nunca resolvido pela
     * revisão vigente do Documento). Relação simples, sem filtro por
     * status da GRD — quem precisa só das GRDs Emitidas filtra na query
     * (`whereHas('grd', fn ($q) => $q->where('status', StatusGrd::Emitida))`).
     */
    public function itensGrd(): HasMany
    {
        return $this->hasMany(GrdItem::class, 'documento_engenharia_revisao_id');
    }

    /**
     * Ciclo 19, Etapa 19.1.CORREÇÃO — Listas de Take Off (LM/LI) desta
     * revisão exata. Nunca resolvido pela revisão vigente do Documento —
     * cada revisão tem suas próprias listas, independentes das demais
     * (D1). Uma revisão pode ter VÁRIAS listas do mesmo tipo (LM-001,
     * LM-002, ambas Material) — corrigido na 19.1.CORREÇÃO, a 19.1
     * original não tinha essa entidade intermediária.
     */
    public function listasEngenharia(): HasMany
    {
        return $this->hasMany(ListaEngenharia::class, 'documento_engenharia_revisao_id');
    }

    /**
     * Conveniência de leitura — todos os itens de TODAS as listas desta
     * revisão, atravessando `ListaEngenharia`. Nunca usado pra
     * escrita/identidade (isso é sempre por lista); só pra telas que
     * precisam do total bruto sem se importar com qual lista.
     */
    public function itensTakeOff(): HasManyThrough
    {
        return $this->hasManyThrough(
            ItemTakeOff::class,
            ListaEngenharia::class,
            'documento_engenharia_revisao_id',
            'lista_engenharia_id'
        );
    }

    /**
     * Ciclo 18, Etapa 18.3.CORREÇÃO — FONTE ÚNICA da ordem canônica de
     * vigência documental, reaproveitada por `DocumentoEngenharia::
     * revisoes()`/`latestRevisao()` (nunca duplicar este trio de
     * orderByDesc em mais de um lugar). "Vigente" = maior `data_emissao`;
     * empate por `created_at` mais recente; empate final por `id`.
     *
     * Prova empírica (auditoria 18.3.CORREÇÃO): MySQL ordena NULL por
     * ÚLTIMO em `ORDER BY ... DESC` (comportamento nativo, verificado
     * com uma query real antes de implementar) — então uma revisão sem
     * `data_emissao` cai automaticamente atrás de qualquer revisão com
     * data real, sem precisar de `COALESCE`/`NULLS LAST` explícito. Uma
     * revisão sem `data_emissao` cadastrada AGORA nunca supera uma
     * emissão documentada com data real — só desempata contra outras
     * revisões TAMBÉM sem data, por created_at/id.
     */
    public function scopeOrdenadasPorVigencia(Builder $query): Builder
    {
        return $query
            ->orderByDesc('data_emissao')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Ciclo 18, Etapa 18.4.CORREÇÃO — filtra a query pra conter APENAS as
     * revisões que são a vigente canônica do seu Documento (a mesma
     * definição de `scopeOrdenadasPorVigencia()`/`ordenadasPorVigencia()->
     * first()`, nunca uma segunda regra) — necessário pra expressar
     * "revisão vigente" dentro de um `whereHas`/`scopeProntas()` SQL-only,
     * sem N+1 e sem PHP `->filter()` em memória.
     *
     * `whereHas('latestRevisao', ...)` NÃO serve pra isso: `latestRevisao`
     * é um HasOne comum (não `ofMany`), então `whereHas` vira um EXISTS
     * que ignora a ordenação e casa com QUALQUER revisão do documento, não
     * só a vigente. Este scope resolve isso via NOT EXISTS (nenhuma
     * revisão do MESMO documento com chave de ordenação maior) — o mesmo
     * critério de 3 níveis de `scopeOrdenadasPorVigencia()`, só que
     * reescrito como comparação de tupla `(data, created_at, id)` pra
     * funcionar como filtro em vez de ORDER BY.
     *
     * `data_emissao` é convertido via `COALESCE(..., '1000-01-01')` só
     * DENTRO desta comparação de tupla (nunca grava/lê esse valor em
     * lugar nenhum) — sentinela seguro (nenhuma emissão real usa esse
     * ano), garante que uma revisão sem data nunca é tratada como "mais
     * nova" que outra com data real na comparação lexicográfica de tupla
     * do MySQL, reproduzindo exatamente "NULL ordena por último em DESC"
     * já provado empiricamente na 18.3.CORREÇÃO — sem decidir vigência
     * por um valor de data inventado.
     *
     * ATENÇÃO: este critério de comparação deve permanecer
     * SEMANTICAMENTE IDÊNTICO a `scopeOrdenadasPorVigencia()` — qualquer
     * mudança lá precisa ser espelhada aqui.
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('documento_engenharia_revisoes as mais_nova')
                ->whereColumn('mais_nova.documento_engenharia_id', 'documento_engenharia_revisoes.documento_engenharia_id')
                ->whereRaw(
                    '(COALESCE(mais_nova.data_emissao, "1000-01-01"), mais_nova.created_at, mais_nova.id) '
                    . '> (COALESCE(documento_engenharia_revisoes.data_emissao, "1000-01-01"), documento_engenharia_revisoes.created_at, documento_engenharia_revisoes.id)'
                );
        });
    }

    /**
     * Ciclo 18, Etapa 18.3 — histórico completo de liberação/revogação
     * desta revisão, mais recente primeiro. Nunca usado pra determinar o
     * estado atual em massa (isso é `ultimaLiberacao`, ofMany) — só pra
     * exibir o histórico completo no modal.
     */
    public function historicoLiberacoes(): HasMany
    {
        return $this->hasMany(RevisaoLiberacao::class, 'revisao_id')
            ->orderByDesc('ocorrido_em')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Último evento de liberação — HasOne (ofMany), mesmo padrão de
     * DocumentoEngenharia::latestRevisao(): eager-load em massa sem N+1
     * (with('latestRevisao.ultimaLiberacao')) pra Lista Mestra inteira.
     * Ordenado por created_at/id (nunca por `ocorrido_em`, que é
     * carimbado por nós mesmos no momento do evento — sempre monotônico
     * por construção, sem o problema de NULL que `latestRevisao()` tem
     * com `data_emissao`).
     */
    public function ultimaLiberacao(): HasOne
    {
        return $this->hasOne(RevisaoLiberacao::class, 'revisao_id')
            ->ofMany(['created_at' => 'max', 'id' => 'max']);
    }

    public function estaLiberadaParaConstrucao(): bool
    {
        return (bool) $this->ultimaLiberacao?->liberada_para_construcao;
    }

    public function liberadaParaConstrucaoEm(): ?Carbon
    {
        return $this->estaLiberadaParaConstrucao() ? $this->ultimaLiberacao?->ocorrido_em : null;
    }

    public function liberadaParaConstrucaoPor(): ?User
    {
        return $this->estaLiberadaParaConstrucao() ? $this->ultimaLiberacao?->alteradoPor : null;
    }
}
