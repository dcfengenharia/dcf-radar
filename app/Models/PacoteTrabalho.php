<?php

namespace App\Models;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PacoteTrabalho extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'pacotes_trabalho';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'parent_id',
        'nome',
        'codigo',
        'external_uid',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(PacoteTrabalho::class, 'parent_id');
    }

    public function filhos(): HasMany
    {
        return $this->hasMany(PacoteTrabalho::class, 'parent_id');
    }

    public function atividades(): HasMany
    {
        return $this->hasMany(Atividade::class, 'pacote_trabalho_id');
    }

    /**
     * Todos os IDs de pacotes descendentes (filhos, netos, ...), em
     * qualquer profundidade — não inclui o próprio $this nem irmãos/pai.
     *
     * Usado pelo Report (peso da tabela de desvios) pra somar o HH de
     * TODA a sub-árvore de um pacote, não só dos filhos diretos.
     *
     * Carrega todos os pacotes da obra numa única query (id + parent_id)
     * e caminha o mapa em memória — evita N+1 de uma query por nível de
     * profundidade, que ficaria caro em EAPs fundas (ex: o caso real do
     * usuário tem um tanque 5 níveis abaixo do pacote raiz).
     */
    public function descendantIds(): array
    {
        $todosPacotes = static::where('obra_id', $this->obra_id)->get(['id', 'parent_id']);
        $filhosPorPai = $todosPacotes->groupBy('parent_id');

        $descendentes = [];
        $paraVisitar = [$this->id];

        while ($paraVisitar !== []) {
            $paiAtual = array_pop($paraVisitar);
            foreach ($filhosPorPai->get($paiAtual, collect()) as $filho) {
                $descendentes[] = $filho->id;
                $paraVisitar[] = $filho->id;
            }
        }

        return $descendentes;
    }

    /**
     * Total de HH da linha de base (Previsto) deste pacote SOMADO com
     * todos os seus descendentes, numa importação específica (ou a mais
     * recente da obra, se nenhuma for informada).
     *
     * Reaproveita a tabela avanco_periodos (já populada pelo importador
     * de cronograma) — a única peça nova aqui é expandir pra toda a
     * sub-árvore via descendantIds(), já que avanco_periodos só guarda
     * HH por atividade, nunca por pacote diretamente.
     *
     * Usa granularidade "mensal" na soma (menos linhas que "semanal" pro
     * mesmo total — os totais fecham exatos entre as duas, por regra já
     * documentada no CLAUDE.md).
     */
    public function totalHhBaseline(?string $cronogramaImportacaoId = null): float
    {
        $pacoteIds = [$this->id, ...$this->descendantIds()];

        $importacaoId = $cronogramaImportacaoId ?? CronogramaImportacao::where('obra_id', $this->obra_id)
            ->orderByDesc('importado_em')
            ->orderByDesc('id')
            ->value('id');

        if (! $importacaoId) {
            return 0.0;
        }

        return (float) AvancoPeriodo::where('cronograma_importacao_id', $importacaoId)
            ->where('serie', SerieAvanco::Previsto->value)
            ->where('granularidade', GranularidadePeriodo::Mensal->value)
            ->whereHas('atividade', fn ($q) => $q->whereIn('pacote_trabalho_id', $pacoteIds))
            ->sum('horas');
    }
}
