<?php

namespace App\Models;

use App\Enums\OrigemAtividade;
use App\Enums\StatusAtividade;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Atividade extends Model
{
    use BelongsToTenant, HasAuthorship, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'pacote_trabalho_id',
        'frente_trabalho_id',
        'etapa_id',
        'disciplina_id',
        'faturamento_direto',
        'entregavel_id',
        'equipe_responsavel_id',
        'personalizado_1_id',
        'personalizado_2_id',
        'personalizado_3_id',
        'personalizado_4_id',
        'personalizado_5_id',
        'responsavel_id',
        'created_by_id',
        'nome',
        'duracao_dias',
        'inicio_planejado',
        'data_termino',
        'status',
        'caminho_critico',
        'percentual_concluido',
        'concluido_em',
        'ordem_manual',
        'codigo_cronograma',
        'external_uid',
        'origem',
        'fora_do_cronograma',
        'is_marco',
        'external_synced_at',
        'baseline_inicio',
        'baseline_termino',
        'real_inicio',
        'real_termino',
        'baseline_horas',
        'work_horas',
        'real_horas',
        'textos',
    ];

    protected $casts = [
        'status'             => StatusAtividade::class,
        'origem'             => OrigemAtividade::class,
        'caminho_critico'    => 'boolean',
        'faturamento_direto' => 'boolean',
        'percentual_concluido' => 'decimal:2',
        'concluido_em'       => 'datetime',
        'ordem_manual'       => 'integer',
        'fora_do_cronograma' => 'boolean',
        'is_marco'           => 'boolean',
        'inicio_planejado'   => 'date',
        'data_termino'       => 'date',
        'baseline_inicio'    => 'date',
        'baseline_termino'   => 'date',
        'real_inicio'        => 'date',
        'real_termino'       => 'date',
        'textos'             => 'array',
        'external_synced_at' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function pacoteTrabalho(): BelongsTo
    {
        return $this->belongsTo(PacoteTrabalho::class, 'pacote_trabalho_id');
    }

    public function frenteTrabalho(): BelongsTo
    {
        return $this->belongsTo(FrenteTrabalho::class, 'frente_trabalho_id');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class, 'etapa_id');
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function entregavel(): BelongsTo
    {
        return $this->belongsTo(Entregavel::class, 'entregavel_id');
    }

    public function equipeResponsavel(): BelongsTo
    {
        return $this->belongsTo(EquipeResponsavel::class, 'equipe_responsavel_id');
    }

    public function personalizado1(): BelongsTo
    {
        return $this->belongsTo(Personalizado1::class, 'personalizado_1_id');
    }

    public function personalizado2(): BelongsTo
    {
        return $this->belongsTo(Personalizado2::class, 'personalizado_2_id');
    }

    public function personalizado3(): BelongsTo
    {
        return $this->belongsTo(Personalizado3::class, 'personalizado_3_id');
    }

    public function personalizado4(): BelongsTo
    {
        return $this->belongsTo(Personalizado4::class, 'personalizado_4_id');
    }

    public function personalizado5(): BelongsTo
    {
        return $this->belongsTo(Personalizado5::class, 'personalizado_5_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function restricoes(): HasMany
    {
        return $this->hasMany(Restricao::class);
    }

    public function comentarios(): HasMany
    {
        return $this->hasMany(AtividadeComentario::class);
    }

    /** Ciclo 17, A.7.1 — anexos PDF pertencem à Atividade (identidade lógica), sobrevivem a nova baseline/avanço/reimportação. */
    public function anexos(): HasMany
    {
        return $this->hasMany(AtividadeAnexo::class);
    }

    public function causasNaoCumprimento(): HasMany
    {
        return $this->hasMany(CausaNaoCumprimento::class);
    }

    public function avancoPeriodos(): HasMany
    {
        return $this->hasMany(AvancoPeriodo::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(AtividadeSnapshot::class);
    }

    public function programacaoSemanalItens(): HasMany
    {
        return $this->hasMany(ProgramacaoSemanalItem::class);
    }

    public function itensSuprimento(): BelongsToMany
    {
        return $this->belongsToMany(ItemSuprimento::class, 'item_suprimento_atividades')
            ->using(ItemSuprimentoAtividade::class);
    }

    /** Ciclo 18, Etapa 18.1 — Documentos de Engenharia dos quais esta Atividade depende. */
    public function documentosEngenharia(): BelongsToMany
    {
        return $this->belongsToMany(DocumentoEngenharia::class, 'documento_engenharia_atividades')
            ->using(DocumentoEngenhariaAtividade::class);
    }

    public function itensProntidaoAtividade(): HasMany
    {
        return $this->hasMany(AtividadeItemProntidao::class);
    }

    /**
     * Ciclo 18, Etapa 18.4.CORREÇÃO — deixou de reimplementar a regra
     * manualmente (restrições + checklist) e passou a delegar 100% pra
     * `scopeProntas()`, a ÚNICA definição de "pronta para comprometimento"
     * de todo o projeto (Plano Semanal/Lookahead/Central/qualquer
     * consumidor futuro) — nunca mais duas fontes que pudessem divergir
     * (achado C da auditoria adversarial da 18.4: `estaPronta()` podia
     * retornar `true` pra uma atividade com Documento de Engenharia
     * bloqueante, porque a checagem de GED só existia na Central). Custo:
     * 1 query (`exists()`) por chamada — aceitável, `estaPronta()` nunca é
     * chamado em loop no código de produção (consultas em lote sempre
     * usam `scopeProntas()` direto, nunca este método por atividade).
     */
    public function estaPronta(): bool
    {
        return static::query()->whereKey($this->id)->prontas()->exists();
    }

    /**
     * Ciclo 18, Etapa 18.4.CORREÇÃO — ÚNICA definição de "pronta para
     * comprometimento/execução" de todo o projeto (Plano Semanal,
     * Lookahead, Central de Prontidão, `estaPronta()`, e qualquer
     * consumidor futuro) — nunca reimplementada em paralelo. Considera:
     *
     * 1. zero restrição bloqueante aberta (regra original, intocada);
     * 2. checklist de prontidão (`itens_prontidao`) 100% concluído
     *    (regra original, intocada);
     * 3. NOVO — nenhum Documento de Engenharia vinculado DIRETAMENTE
     *    (Ciclo 18.1, `documentosEngenharia()`) que esteja
     *    operacionalmente NÃO liberado para construção
     *    (`DocumentoEngenharia::scopeNaoLiberados()`, que por sua vez
     *    reaproveita — nunca duplica — a mesma definição de revisão
     *    vigente da 18.3.CORREÇÃO e de último evento de liberação da
     *    18.3). Atividade sem NENHUM documento vinculado nunca é afetada
     *    por esta cláusula (`whereDoesntHave` sobre um conjunto vazio é
     *    sempre verdadeiro).
     *
     * Guarda cross-obra (defesa em profundidade, mesma proteção que já
     * existia só na Central antes desta correção — agora também aqui, na
     * fonte canônica): `whereColumn('documentos_engenharia.obra_id',
     * 'atividades.obra_id')` garante que um pivô corrompido apontando pra
     * Documento de OUTRA obra (mesmo tenant) nunca bloqueia. Tenant já
     * garantido pelo global scope de `BelongsToTenant` (automático em
     * `documentosEngenharia()`/`DocumentoEngenhariaRevisao`/
     * `RevisaoLiberacao`).
     *
     * 100% SQL — `whereDoesntHave` vira um único `NOT EXISTS` correlato
     * na mesma query, nunca uma query por atividade (sem N+1, mesmo em
     * lote com centenas de atividades).
     */
    public function scopeProntas(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('restricoes', function (Builder $q) {
                $q->where('bloqueante', true)
                  ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros']);
            })
            ->where(function (Builder $q) {
                $q->whereRaw(
                    '0 = (SELECT COUNT(*) FROM itens_prontidao ip WHERE ip.obra_id = atividades.obra_id)'
                )->orWhereRaw(
                    '(SELECT COUNT(*) FROM itens_prontidao ip WHERE ip.obra_id = atividades.obra_id) = ' .
                    '(SELECT COUNT(*) FROM atividade_itens_prontidao aip WHERE aip.atividade_id = atividades.id AND aip.concluido = 1)'
                );
            })
            ->whereDoesntHave('documentosEngenharia', function (Builder $q) {
                $q->whereColumn('documentos_engenharia.obra_id', 'atividades.obra_id')
                    ->naoLiberados();
            });
    }

    public function scopeNaoProntas(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereHas('restricoes', function (Builder $r) {
                $r->where('bloqueante', true)
                  ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros']);
            })->orWhereRaw(
                '0 < (SELECT COUNT(*) FROM itens_prontidao ip WHERE ip.obra_id = atividades.obra_id) AND ' .
                '(SELECT COUNT(*) FROM itens_prontidao ip WHERE ip.obra_id = atividades.obra_id) > ' .
                '(SELECT COUNT(*) FROM atividade_itens_prontidao aip WHERE aip.atividade_id = atividades.id AND aip.concluido = 1)'
            )->orWhereHas('documentosEngenharia', function (Builder $q) {
                $q->whereColumn('documentos_engenharia.obra_id', 'atividades.obra_id')
                    ->naoLiberados();
            });
        });
    }
}
