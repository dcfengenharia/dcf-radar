<?php

namespace App\Models;

use App\Enums\HealthCheckSeveridade;
use App\Enums\StatusPlanoAcao;
use App\Enums\StatusRestricao;
use App\Exceptions\PlanoAcaoDuplicadoException;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuthorship;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\PlanoAcao\SobreposicaoUid;
use App\Support\HealthCheck\PlanoAcao\UidExtractor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Plano de Ação do Cronograma (Fase 4) — sobrevive às reimportações,
 * independente de uma CronogramaImportacao específica (Modelo B, aprovado
 * no diagnóstico da Fase 4). `uids_referencia` é a identidade usada por
 * PlanoAcaoReconciliador pra reconhecer o mesmo problema entre importações
 * — é atualizada a cada reconciliação, nunca fica presa ao valor da
 * criação. Nunca escreve em Score/HealthCheck (só lê, indiretamente, via
 * `importacaoOrigem`).
 */
class PlanoAcao extends Model
{
    use BelongsToTenant, HasAuthorship, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'planos_acao';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'cronograma_importacao_origem_id',
        'regra_id',
        'titulo',
        'recomendacao',
        'responsavel_id',
        'created_by_id',
        'prazo',
        'status',
        'uids_referencia',
        'resolvida_em',
    ];

    protected $casts = [
        'prazo' => 'date',
        'status' => StatusPlanoAcao::class,
        'uids_referencia' => 'array',
        'resolvida_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function importacaoOrigem(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'cronograma_importacao_origem_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function reconciliacoes(): HasMany
    {
        return $this->hasMany(PlanoAcaoReconciliacao::class);
    }

    /**
     * Reconciliação mais recente desta ação — HasOne (ofMany), mesmo padrão
     * de `DocumentoEngenharia::latestRevisao()` (`app/Models/DocumentoEngenharia.php`):
     * permite eager-load em massa (`with('ultimaReconciliacao')`) e
     * `whereHas('ultimaReconciliacao', ...)` pra filtrar pela situação da
     * ÚLTIMA análise de verdade, sem N+1. `null` quando a ação nunca foi
     * reconciliada (recém-criada, nenhuma importação rodou desde então).
     */
    public function ultimaReconciliacao(): HasOne
    {
        return $this->hasOne(PlanoAcaoReconciliacao::class)->ofMany(['created_at' => 'max']);
    }

    /**
     * Severidade da regra que originou esta ação — Fase 4.3, decisão do
     * usuário: NUNCA persistida em `planos_acao` (severidade é característica
     * da REGRA, não da ação, e é 100% derivável sem ambiguidade a qualquer
     * momento a partir do Health Check da importação de origem, já que
     * severidade nunca varia entre ocorrências da mesma `regra_id` — mesmo
     * princípio já usado pra decidir que "quantidade"/"uid" são os únicos
     * critérios confiáveis de comparação na Fase 4/4.1).
     *
     * `null` só quando a importação de origem não tem Health Check
     * persistido (registro anterior à Fase 1) ou quando, por algum motivo,
     * a regra não aparece mais nos findings daquela importação — nunca
     * inventa um valor.
     */
    public function severidadeDaRegra(): ?HealthCheckSeveridade
    {
        $findings = $this->importacaoOrigem?->healthCheck?->resultado()->findings ?? [];

        foreach ($findings as $finding) {
            if ($finding->regraId === $this->regra_id) {
                return $finding->severidade;
            }
        }

        return null;
    }

    /**
     * Resolve `uids_referencia` (conjunto de external_uid, sem código/nome)
     * pras atividades reais da obra — usado só pra exibição no painel
     * expansível (Fase 4.3), nunca duplica o Health Check (lê a tabela
     * `atividades` ao vivo, não o JSON congelado do finding). Uma atividade
     * cujo `external_uid` não existir mais na obra (raro — ex.: uid só
     * apareceu numa importação muito antiga) simplesmente não aparece,
     * nunca gera erro.
     *
     * @return Collection<int, Atividade>
     */
    public function atividadesRelacionadas(): Collection
    {
        if (empty($this->uids_referencia)) {
            return new Collection();
        }

        return Atividade::where('obra_id', $this->obra_id)
            ->whereIn('external_uid', $this->uids_referencia)
            ->get(['id', 'external_uid', 'codigo_cronograma', 'nome']);
    }

    /**
     * Cria um PlanoAcao a partir de um HealthCheckFinding específico —
     * congela título/recomendação (nunca relê a regra ao vivo depois) e
     * extrai a identidade inicial via UidExtractor (cobre finding plano
     * e agrupado igualmente, sem distinção especial aqui).
     *
     * Valida que o responsável (se informado) tem vínculo com a obra —
     * decisão de mantê-la no domínio, não só numa camada de UI futura,
     * porque essa é uma invariante de segurança, não uma conveniência de
     * formulário (Fase 4 diagnóstico, Etapa 9).
     *
     * Duplicação controlada (Fase 4.2, decisão do usuário): bloqueia só
     * quando já existe uma ação Aberta da MESMA obra + regra_id cujo
     * `uids_referencia` tem sobreposição com ESTE finding específico —
     * MESMO critério de identidade do PlanoAcaoReconciliador
     * (SobreposicaoUid), nunca uma heurística nova. Findings distintos da
     * mesma regra sem sobreposição de uid entre si (ex.: 2 ciclos STRUCT-005
     * separados) coexistem livremente — cada um pode virar sua própria ação.
     *
     * @throws \InvalidArgumentException quando o responsável informado não tem acesso à obra
     * @throws PlanoAcaoDuplicadoException quando já existe uma ação aberta pra este mesmo problema
     */
    public static function criarDeFinding(
        HealthCheckFinding $finding,
        CronogramaImportacao $importacaoOrigem,
        string $obraId,
        ?User $responsavel = null,
        ?string $prazo = null,
    ): self {
        if ($responsavel !== null && ! $responsavel->temAcessoAObra($obraId)) {
            throw new \InvalidArgumentException('O responsável informado não tem acesso a esta obra.');
        }

        $uidsDoFinding = UidExtractor::extrair($finding->atividades);

        $acaoExistente = self::where('obra_id', $obraId)
            ->where('regra_id', $finding->regraId)
            ->where('status', StatusPlanoAcao::Aberta->value)
            ->get()
            ->first(fn (self $acao) => SobreposicaoUid::temSobreposicao($uidsDoFinding, $acao->uids_referencia));

        if ($acaoExistente !== null) {
            throw new PlanoAcaoDuplicadoException($acaoExistente);
        }

        return self::create([
            'obra_id' => $obraId,
            'cronograma_importacao_origem_id' => $importacaoOrigem->id,
            'regra_id' => $finding->regraId,
            'titulo' => $finding->titulo,
            'recomendacao' => $finding->recomendacao,
            'responsavel_id' => $responsavel?->id,
            'prazo' => $prazo,
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => $uidsDoFinding,
        ]);
    }

    /**
     * Ciclo 11 (Etapa B) — "PlanoAcao → decisão humana explícita →
     * Restrição por atividade". Cria, no máximo, 1 Restricao por atividade
     * selecionada, cada uma vinculada a este PlanoAcao via
     * `origem_plano_acao_id`. Transformação explícita e manual apenas —
     * SEM sincronização de ciclo de vida depois disso (resolver/reabrir/
     * cancelar este PlanoAcao nunca propaga pra Restrição nenhuma, e
     * vice-versa; ver CLAUDE.md).
     *
     * Revalida TUDO no servidor — a lista recebida é só a INTENÇÃO do
     * usuário (seleção feita no Alpine/Livewire), nunca fonte confiável:
     * (i) a atividade precisa pertencer a esta obra; (ii) seu
     * `external_uid` precisa estar em `uids_referencia` deste PlanoAcao
     * (mesma identidade já usada por `atividadesRelacionadas()` — nunca
     * um id solto que o cliente possa forjar); (iii) não pode estar
     * `fora_do_cronograma`; (iv) não pode já ter uma Restricao originada
     * deste MESMO PlanoAcao. A proteção DEFINITIVA contra duplicidade é o
     * índice `UNIQUE(tenant_id, origem_plano_acao_id, atividade_id)` da
     * migration — a checagem em memória aqui é só a via rápida (evita uma
     * query de INSERT fadada a falhar no caso comum); uma corrida
     * concorrente que escape dela é pega pelo catch de
     * `QueryException`/1062 abaixo, tratada como "já vinculada", nunca
     * propagada crua.
     *
     * Este método NÃO abre transação própria — atomicidade é
     * responsabilidade do CHAMADOR (em produção, sempre dentro de
     * `transacaoSegura()`/`DB::transaction()` em ⚡plano-acao.blade.php).
     * O catch de 1062 dentro do `foreach` continuar a iteração sem abortar
     * a transação em andamento depende do comportamento do MySQL/InnoDB
     * (um erro de statement não invalida a transação inteira, diferente
     * de Postgres) — seguro porque o projeto é MySQL-only.
     *
     * Sem nenhum side effect sobre este PlanoAcao (status/uids_referencia
     * intocados) e sem criar `RestricaoAcao` — a origem já é 100%
     * rastreável por `origem_plano_acao_id` + os timestamps da própria
     * Restricao, sem precisar de um mecanismo de auditoria paralelo.
     *
     * @param  string[]  $atividadeIdsSelecionados
     * @return array{criadas:int, ignoradasForaDoCronograma:int, ignoradasJaVinculadas:int, ignoradasInvalidas:int}
     */
    public function transformarEmRestricoes(array $atividadeIdsSelecionados): array
    {
        $atividadeIdsSelecionados = array_values(array_unique($atividadeIdsSelecionados));

        $atividadesValidas = Atividade::where('obra_id', $this->obra_id)
            ->whereIn('external_uid', $this->uids_referencia ?? [])
            ->whereIn('id', $atividadeIdsSelecionados)
            ->get(['id', 'fora_do_cronograma']);

        $ignoradasInvalidas = count($atividadeIdsSelecionados) - $atividadesValidas->count();

        $elegiveis = $atividadesValidas->reject(fn (Atividade $a) => $a->fora_do_cronograma);
        $ignoradasForaDoCronograma = $atividadesValidas->count() - $elegiveis->count();

        $idsJaVinculados = Restricao::where('origem_plano_acao_id', $this->id)
            ->whereIn('atividade_id', $elegiveis->pluck('id'))
            ->pluck('atividade_id')
            ->all();

        $paraCriar = $elegiveis->reject(fn (Atividade $a) => in_array($a->id, $idsJaVinculados, true));
        $ignoradasJaVinculadas = count($idsJaVinculados);

        $criadas = 0;

        foreach ($paraCriar as $atividade) {
            try {
                Restricao::create([
                    'atividade_id' => $atividade->id,
                    'origem_plano_acao_id' => $this->id,
                    'descricao' => "Plano de Ação: {$this->titulo}",
                    'bloqueante' => false,
                    'categoria_id' => null,
                    'status' => StatusRestricao::Aberta->value,
                    'aberta_em' => now(),
                ]);

                $criadas++;
            } catch (\Illuminate\Database\QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }

                // Corrida concorrente: outra requisição criou a mesma
                // combinação (origem_plano_acao_id, atividade_id) entre a
                // checagem acima e este INSERT — quem realmente protegeu
                // foi o índice UNIQUE, aqui só contabilizamos igual a
                // "já vinculada", nunca deixando a exceção subir crua.
                $ignoradasJaVinculadas++;
            }
        }

        return compact('criadas', 'ignoradasForaDoCronograma', 'ignoradasJaVinculadas', 'ignoradasInvalidas');
    }
}
