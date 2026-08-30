<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ciclo 20, Etapa 20.4 — onde uma Saída física (`MovimentacaoEstoque`
 * tipo Saida) foi EFETIVAMENTE utilizada. Camada gerencial nova,
 * distinta dos 3 fatos já existentes (Destinação Planejada = intenção
 * lógica; Reserva = comprometimento físico; Saída = o que deixou o
 * estoque) — nenhum deles é reescrito por esta camada.
 *
 * **Sempre referencia uma Saída, nunca outra origem** (Seção 6 do
 * pedido) — `movimentacao_estoque_id` é obrigatório; a validação de que
 * a Movimentação referenciada é do tipo Saida vive na Action
 * (`App\Actions\Estoque\RegistrarAplicacaoMaterialEstoque`), não aqui.
 *
 * **Pacote por LINHA, nunca herdado silenciosamente da Saída** (decisão
 * do usuário, Seções 17/18/19): `item_suprimento_id` é nullable e
 * independente por Aplicação — quando a Saída já tem um Pacote fixo
 * (`movimentacoes_estoque.item_suprimento_id` não-nulo), toda Aplicação
 * dela precisa usar EXATAMENTE esse mesmo Pacote (nunca um diferente,
 * nunca silenciosamente ignorado) — guard vive na Action. Quando a
 * Saída não tem Pacote (null), cada linha de Aplicação pode declarar o
 * seu próprio, inclusive Pacotes DIFERENTES entre linhas da mesma
 * Saída (Seção 19 — ex.: 500m repartidos entre Pacote Elétrica e
 * Pacote Instrumentação) — ou continuar null quando a demanda ainda
 * não foi identificada.
 *
 * **Frente sempre real, nunca fake** (Seção 10) — `frente_trabalho_id`
 * é NOT NULL; sem Frente conhecida, a linha simplesmente não é criada
 * (a Saída fica com pendência > 0). `frenteTrabalho()` usa
 * `withTrashed()` na própria definição (mesmo padrão de
 * `DestinacaoPlanejadaMaterial`/`MovimentacaoEstoque`) — histórico de
 * Aplicação sobrevive a uma Frente arquivada depois.
 *
 * **Correção — editável enquanto a Saída não estiver 100% conciliada**
 * (decisão do usuário, Seção 12): `App\Support\Estoque\
 * PoliticaConciliacaoAplicacao::saidaEstaFechada()` é a ÚNICA fonte de
 * verdade de "está fechada" (derivada, nunca uma coluna de status
 * própria — `SUM(aplicações) >= quantidade` da Saída). Uma vez fechada,
 * NENHUMA Aplicação daquela Saída pode ser criada/editada/excluída,
 * mesmo pra "corrigir" — reabrir uma conciliação já fechada excluindo
 * uma linha é explicitamente proibido. `App\Observers\
 * AplicacaoMaterialEstoqueObserver` bloqueia isso incondicionalmente
 * (barreira semântica); a atomicidade de verdade vem de toda escrita
 * passar pelas 3 Actions (`RegistrarAplicacaoMaterialEstoque`/
 * `AtualizarAplicacaoMaterialEstoque`/`RemoverAplicacaoMaterialEstoque`),
 * que sempre travam a `MovimentacaoEstoque` (Saída) ANTES de qualquer
 * SUM — mesmo total order já usado por `RegistrarSaidaEstoque`/
 * `CriarReservaEstoque`.
 */
class AplicacaoMaterialEstoque extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'aplicacoes_material_estoque';

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'movimentacao_estoque_id',
        'frente_trabalho_id',
        'item_suprimento_id',
        'quantidade',
        'aplicado_em',
        'registrado_por',
        'atualizado_por',
        'observacao',
    ];

    protected $casts = [
        'quantidade' => 'decimal:3',
        'aplicado_em' => 'date',
    ];

    public function movimentacaoEstoque(): BelongsTo
    {
        return $this->belongsTo(MovimentacaoEstoque::class);
    }

    public function frenteTrabalho(): BelongsTo
    {
        return $this->belongsTo(FrenteTrabalho::class, 'frente_trabalho_id')->withTrashed();
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(ItemSuprimento::class, 'item_suprimento_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por');
    }
}
