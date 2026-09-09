<?php

namespace App\Models;

use App\Enums\StatusAtividade;
use App\Enums\StatusProgramacaoSemanal;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramacaoSemanal extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'programacoes_semanais';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'semana_inicio',
        'semana_fim',
        'congelada_em',
        'superseded_at',
        'criado_por',
        'status',
        'fechada_em',
        'fechada_por',
        'versao',
        'revisao_de_id',
    ];

    protected $casts = [
        'semana_inicio' => 'date',
        'semana_fim' => 'date',
        'congelada_em' => 'datetime',
        'superseded_at' => 'datetime',
        'fechada_em' => 'datetime',
        'status' => StatusProgramacaoSemanal::class,
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function fechadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fechada_por');
    }

    public function revisaoDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revisao_de_id');
    }

    public function revisoes(): HasMany
    {
        return $this->hasMany(self::class, 'revisao_de_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ProgramacaoSemanalItem::class);
    }

    /**
     * Único ponto de resolução de "qual é a programação vigente desta
     * semana" — a versão mais recente (`versao` mais alta), aberta ou
     * fechada. Substitui qualquer lookup direto por obra_id+semana_inicio,
     * já que agora pode haver mais de uma versão (histórico de revisões)
     * pra uma mesma semana.
     */
    public static function ativaPara(Work $obra, string $semanaInicio): ?self
    {
        return static::where('obra_id', $obra->id)
            ->where('semana_inicio', $semanaInicio)
            ->orderByDesc('versao')
            ->first();
    }

    public function estaFechada(): bool
    {
        return $this->status === StatusProgramacaoSemanal::Fechada;
    }

    /**
     * Ciclo 17, A.9.5 — resolve qual versão era VIGENTE num instante
     * histórico qualquer (nunca "a mais recente hoje" — ver `ativaPara()`,
     * que continua intocada e usada só pro fluxo ao vivo de comprometer/
     * revisar). Uma versão está vigente em `$instante` quando já existia
     * (`congelada_em <= $instante`) e ainda não tinha sido substituída por
     * uma revisão (`superseded_at` nulo, ou só passou a valer DEPOIS de
     * `$instante`). Cadeia de revisões é estritamente linear
     * (`CriarRevisaoProgramacaoSemanal` só revisa a versão Fechada mais
     * recente), então no máximo uma versão satisfaz os dois critérios ao
     * mesmo tempo — `orderByDesc('versao')` é só uma garantia extra.
     *
     * Versões revisadas ANTES da coluna `superseded_at` existir (A.9.5)
     * ficam com esse campo `null` pra sempre (sem backfill, mesmo
     * princípio de toda fotografia do Ciclo 17) — pra elas, esta consulta
     * degrada sozinha pra "versão mais recente cuja `congelada_em` já
     * tinha passado", sem inventar um instante de substituição que
     * ninguém registrou.
     */
    public static function vigenteEm(Work $obra, string $semanaInicio, $instante): ?self
    {
        return static::where('obra_id', $obra->id)
            ->where('semana_inicio', $semanaInicio)
            ->where('congelada_em', '<=', $instante)
            ->where(function ($query) use ($instante) {
                $query->whereNull('superseded_at')
                    ->orWhere('superseded_at', '>', $instante);
            })
            ->orderByDesc('versao')
            ->first();
    }

    /**
     * % de aderência: itens da programação cuja Atividade (lida ao vivo,
     * nunca duplicada aqui — decisão do usuário de reaproveitar
     * Atividade.status em vez de um campo de status próprio no item)
     * está Concluída, dividido pelo total de itens. `null` sem itens
     * (evita divisão por zero — mesmo idioma de "traço" já usado na
     * coluna "% do Projeto" do Plano Semanal).
     *
     * Ciclo 24 — correção de consistência com o PPC canônico
     * (`⚡relatorios-restricoes.blade.php::ppcQuery()`): "cumprida" agora
     * exige, além de `status === Concluido`, que `concluido_em` caia
     * DENTRO da janela desta semana (`concluido_em <= semana_fim`) — o
     * MESMO corte temporal que o PPC histórico já usa. Antes desta
     * correção, `aderencia()` só olhava o status ATUAL sem nenhum corte
     * de data — uma atividade comprometida na Semana 36 e só concluída
     * fisicamente na Semana 37 (concluido_em cai depois de 36) fazia
     * "Minhas Programações" mostrar a Semana 36 como cumprida, enquanto o
     * PPC (que já respeitava o corte) continuava mostrando corretamente
     * como não cumprida — a mesma atividade/compromisso relatada de duas
     * formas contraditórias em duas telas. Nunca precisou de migration:
     * `concluido_em` e `semana_fim` já existiam, só a lógica de
     * comparação estava incompleta.
     */
    public function aderencia(): ?float
    {
        $itens = $this->itens()->with('atividade:id,status,concluido_em')->get();

        $total = $itens->count();
        if ($total === 0) {
            return null;
        }

        $semanaFim = $this->semana_fim->toDateString();

        $concluidos = $itens->filter(function (ProgramacaoSemanalItem $item) use ($semanaFim) {
            $atividade = $item->atividade;

            return $atividade?->status === StatusAtividade::Concluido
                && $atividade->concluido_em !== null
                && $atividade->concluido_em->toDateString() <= $semanaFim;
        })->count();

        return round(($concluidos / $total) * 100, 1);
    }
}
