<?php

namespace App\Models;

use App\Enums\StatusEtapaRequisicaoCompra;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 19, Etapa 19.4 — instância própria (não compartilhada com o
 * mecanismo legado de `ItemSuprimentoEtapa`) do fluxo de compra aplicada
 * a UMA RC. `data_prevista` é congelada na emissão via
 * `App\Support\DiasUteisCalculator`; `data_realizada` só é escrita por
 * `App\Actions\Suprimentos\RegistrarConclusaoEtapaRequisicaoCompra`.
 */
class RequisicaoCompraEtapa extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'requisicao_compra_etapas';

    protected $fillable = [
        'tenant_id',
        'requisicao_compra_id',
        'etapa_fluxo_suprimento_id',
        'ordem',
        'nome_snapshot',
        'prazo_dias_snapshot',
        'data_prevista',
        'data_realizada',
        'realizada_por',
        'observacao',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'prazo_dias_snapshot' => 'integer',
        'data_prevista' => 'date',
        'data_realizada' => 'date',
    ];

    public function requisicaoCompra(): BelongsTo
    {
        return $this->belongsTo(RequisicaoCompra::class);
    }

    public function etapaTemplate(): BelongsTo
    {
        return $this->belongsTo(EtapaFluxoSuprimento::class, 'etapa_fluxo_suprimento_id');
    }

    public function realizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizada_por');
    }

    public function estaConcluida(): bool
    {
        return $this->data_realizada !== null;
    }

    public function status(): StatusEtapaRequisicaoCompra
    {
        if ($this->estaConcluida()) {
            return StatusEtapaRequisicaoCompra::Concluida;
        }

        if ($this->data_prevista !== null && $this->data_prevista->lt(Carbon::today())) {
            return StatusEtapaRequisicaoCompra::Atrasada;
        }

        return StatusEtapaRequisicaoCompra::Pendente;
    }
}
