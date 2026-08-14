<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\HealthCheck\HealthCheckResultado;
use App\Support\HealthCheck\Score\AcaoRecomendada;
use App\Support\HealthCheck\Score\FaixaScore;
use App\Support\HealthCheck\Score\ScoreDimensao;
use App\Support\HealthCheck\Score\ScoreResultado;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronogramaImportacaoHealthCheck extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'cronograma_importacao_health_checks';

    protected $fillable = [
        'tenant_id',
        'cronograma_importacao_id',
        'total_criticos',
        'total_altos',
        'total_medios',
        'total_baixos',
        'total_informativos',
        'total_ocorrencias',
        'importado_com_alertas',
        'versao_regras',
        'findings',
        'analisado_em',
        'score',
        'faixa_score',
        'cobertura',
        'score_por_dimensao',
        'mapa_acoes',
        'potencial_recuperavel',
        'versao_score',
    ];

    protected $casts = [
        'findings' => 'array',
        'importado_com_alertas' => 'boolean',
        'analisado_em' => 'datetime',
        'total_criticos' => 'integer',
        'total_altos' => 'integer',
        'total_medios' => 'integer',
        'total_baixos' => 'integer',
        'total_informativos' => 'integer',
        'total_ocorrencias' => 'integer',
        'score' => 'integer',
        'faixa_score' => FaixaScore::class,
        'cobertura' => 'integer',
        'score_por_dimensao' => 'array',
        'mapa_acoes' => 'array',
        'potencial_recuperavel' => 'integer',
    ];

    /** Versão do catálogo de regras da Fase 1 — mudar quando o conjunto de regras evoluir. */
    public const VERSAO_REGRAS_ATUAL = '1.0';

    public function cronogramaImportacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class, 'cronograma_importacao_id');
    }

    /** Reidrata o HealthCheckResultado a partir do JSON gravado. */
    public function resultado(): HealthCheckResultado
    {
        return HealthCheckResultado::fromArray(['findings' => $this->findings]);
    }

    /**
     * Reidrata o ScoreResultado a partir das colunas gravadas — `null` para
     * registros anteriores à Fase 3 (Score), que nunca tiveram essas colunas
     * preenchidas (sem backfill, sem Score retroativo inventado).
     */
    public function scoreResultado(): ?ScoreResultado
    {
        if ($this->score === null) {
            return null;
        }

        return new ScoreResultado(
            score: $this->score,
            faixa: $this->faixa_score,
            cobertura: $this->cobertura,
            porDimensao: array_map(
                fn (array $d) => ScoreDimensao::fromArray($d),
                $this->score_por_dimensao ?? []
            ),
            mapaAcoes: array_map(
                fn (array $a) => AcaoRecomendada::fromArray($a),
                $this->mapa_acoes ?? []
            ),
            potencialRecuperavel: $this->potencial_recuperavel,
            versaoFormula: $this->versao_score,
        );
    }

    /**
     * Monta o array de atributos prontos pra Model::create(), a partir do
     * MESMO array serializado (HealthCheckResultado::toArray()) que a tela
     * de prévia calculou e mostrou ao usuário — nunca recalcula o Health
     * Check aqui, só agrega os totais pra colunas de leitura rápida.
     */
    public static function camposParaPersistir(array $resultadoSerializado): array
    {
        $resultado = HealthCheckResultado::fromArray($resultadoSerializado);
        $porSeveridade = $resultado->totalPorSeveridade();

        return [
            'total_criticos' => $porSeveridade['critico'],
            'total_altos' => $porSeveridade['alto'],
            'total_medios' => $porSeveridade['medio'],
            'total_baixos' => $porSeveridade['baixo'],
            'total_informativos' => $porSeveridade['informativo'],
            'total_ocorrencias' => $resultado->totalOcorrencias(),
            'importado_com_alertas' => $resultado->temAlertas(),
            'versao_regras' => self::VERSAO_REGRAS_ATUAL,
            'findings' => $resultadoSerializado['findings'],
            'analisado_em' => now(),
        ];
    }

    /**
     * Monta o array de atributos de Score prontos pra Model::create() —
     * irmão de camposParaPersistir(), nunca recalcula nada (o ScoreResultado
     * já veio pronto do ScoreCalculator, única fonte de verdade do cálculo).
     * `versao_score` vem de ScoreResultado::$versaoFormula, que por sua vez
     * já veio de ScoreCalculator::VERSAO_FORMULA — nunca duplicada aqui.
     */
    public static function camposDeScoreParaPersistir(ScoreResultado $score): array
    {
        return [
            'score' => $score->score,
            'faixa_score' => $score->faixa->value,
            'cobertura' => $score->cobertura,
            'score_por_dimensao' => array_map(fn (ScoreDimensao $d) => $d->toArray(), $score->porDimensao),
            'mapa_acoes' => array_map(fn (AcaoRecomendada $a) => $a->toArray(), $score->mapaAcoes),
            'potencial_recuperavel' => $score->potencialRecuperavel,
            'versao_score' => $score->versaoFormula,
        ];
    }
}
