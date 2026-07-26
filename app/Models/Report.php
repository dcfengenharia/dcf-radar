<?php

namespace App\Models;

use App\Enums\StatusReport;
use App\Models\Concerns\BelongsToTenant;
use App\Notifications\ReportEmitidoNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Notification;

/**
 * Report semanal de avanço da obra.
 *
 * IMPORTANTE — filosofia de "fotografia": todos os números das curvas
 * (HH por período, %, término, peso/desvio/impacto) são calculados e
 * gravados no momento em que o rascunho é gerado (ver
 * App\Services\ReportGerador::gerarRascunho()). Nada dentro de um Report
 * já criado volta a consultar AvancoPeriodo/PacoteTrabalho ao vivo — um
 * report já emitido e mostrado ao cliente nunca muda sozinho depois de
 * uma reimportação do cronograma. Mesma filosofia de AtividadeSnapshot
 * e LinhaBase.
 *
 * O status (rascunho/emitido) só muda através do método emitir() abaixo
 * — nunca via update() genérico, pra manter emitido_por/emitido_em
 * sempre consistentes com o status.
 */
class Report extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'periodo_referencia',
        'data_status',
        'status',
        'linha_base_id',
        'cronograma_importacao_id',
        'titulo',
        'criado_por',
        'emitido_por',
        'emitido_em',
    ];

    protected $casts = [
        'periodo_referencia' => 'date',
        'data_status' => 'date',
        'status' => StatusReport::class,
        'emitido_em' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function linhaBase(): BelongsTo
    {
        return $this->belongsTo(LinhaBase::class);
    }

    public function cronogramaImportacao(): BelongsTo
    {
        return $this->belongsTo(CronogramaImportacao::class);
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function emissor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitido_por');
    }

    public function curvas(): HasMany
    {
        return $this->hasMany(ReportCurva::class)->orderBy('ordem');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(ReportFoto::class)->orderBy('ordem');
    }

    public function comentarios(): HasMany
    {
        return $this->hasMany(ReportComentario::class)->latest();
    }

    public function indicadoresSemana(): HasMany
    {
        return $this->hasMany(ReportIndicadorSemana::class);
    }

    public function estaRascunho(): bool
    {
        return $this->status === StatusReport::Rascunho;
    }

    public function estaEmitido(): bool
    {
        return $this->status === StatusReport::Emitido;
    }

    /**
     * Único lugar que transiciona o status pra emitido. Ao emitir, o
     * report vira somente-leitura pro planejamento e passa a ser visível
     * (e comentável) por todos os usuários com acesso à obra — ver
     * App\Policies\ReportPolicy.
     */
    public function emitir(User $usuario): void
    {
        $this->update([
            'status' => StatusReport::Emitido,
            'emitido_por' => $usuario->id,
            'emitido_em' => now(),
        ]);

        $destinatarios = $this->obra->users()
            ->where('users.id', '!=', $usuario->id)
            ->get();

        if ($destinatarios->isNotEmpty()) {
            // Notificar os demais usuários é efeito colateral, não o efeito
            // principal desta ação — uma falha de infraestrutura (fila/
            // broadcast indisponível) nunca pode impedir a emissão de valer,
            // já que o status já foi transicionado acima. Best-effort: loga
            // e segue.
            try {
                Notification::send($destinatarios, new ReportEmitidoNotification($this, $usuario));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Reports rascunho só aparecem pra quem tem permissão de "editar" em
     * report.relatorios na obra; reports emitidos aparecem pra qualquer
     * usuário com acesso à obra. Reaproveitado tanto pela listagem
     * quanto por qualquer outro lugar que precise filtrar reports
     * "visíveis" sem repetir essa regra.
     *
     * Recebe $obraId explicitamente (em vez de ler $this->obra_id) porque
     * um escopo local roda sobre a instância "vazia" usada pra montar a
     * query, não sobre os registros já filtrados por ela — $this->obra_id
     * seria sempre null aqui.
     */
    public function scopeVisivelPara(Builder $query, User $usuario, string $obraId): Builder
    {
        if ($usuario->temPermissaoNaObra($obraId, 'report.relatorios', 'editar')) {
            return $query;
        }

        return $query->where('status', StatusReport::Emitido->value);
    }
}
