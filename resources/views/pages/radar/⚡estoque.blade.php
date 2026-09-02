<?php

use App\Actions\Estoque\AssociarMaterialAoItemTakeOff;
use App\Actions\Estoque\AtualizarRascunhoOrdemIndustrializacao;
use App\Actions\Estoque\AtualizarAplicacaoMaterialEstoque;
use App\Actions\Estoque\AtualizarDestinacaoPlanejada;
use App\Actions\Estoque\CriarOrdemIndustrializacao;
use App\Actions\Estoque\CriarReservaEstoque;
use App\Actions\Estoque\EmitirOrdemIndustrializacao;
use App\Actions\Estoque\LiberarReservaEstoque;
use App\Actions\Estoque\RegistrarAplicacaoMaterialEstoque;
use App\Actions\Estoque\RegistrarConsumoIndustrializacao;
use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Estoque\RegistrarEntregaProdutoIndustrializado;
use App\Actions\Estoque\RegistrarProducaoIndustrializada;
use App\Actions\Estoque\RegistrarRemessaIndustrializacao;
use App\Actions\Estoque\RegistrarSaidaEstoque;
use App\Actions\Estoque\RegistrarTransferenciaEstoque;
use App\Actions\Estoque\RemoverAplicacaoMaterialEstoque;
use App\Actions\Estoque\CriarInventarioEstoque;
use App\Actions\Estoque\IniciarInventarioEstoque;
use App\Actions\Estoque\RegistrarContagemInventario;
use App\Actions\Estoque\AdicionarItemInesperadoInventario;
use App\Actions\Estoque\MoverInventarioParaAnalise;
use App\Actions\Estoque\AprovarAjusteInventario;
use App\Actions\Estoque\ConcluirInventarioEstoque;
use App\Actions\Estoque\CancelarInventarioEstoque;
use App\Enums\DirecaoRemessaIndustrializacao;
use App\Enums\ModalidadeEntregaProduto;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\StatusReservaEstoque;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\AplicacaoConciliacaoFechadaException;
use App\Exceptions\AplicacaoConciliacaoInvalidaException;
use App\Exceptions\AssociacaoMaterialInvalidaException;
use App\Exceptions\ConsumoIndustrializacaoInvalidaException;
use App\Exceptions\DestinacaoPlanejadaImutavelException;
use App\Exceptions\DestinacaoPlanejadaInvalidaException;
use App\Exceptions\EntradaEstoqueInvalidaException;
use App\Exceptions\EntregaProdutoIndustrializadoInvalidaException;
use App\Exceptions\ItemTakeOffMaterialImutavelException;
use App\Exceptions\OrdemIndustrializacaoImutavelException;
use App\Exceptions\OrdemIndustrializacaoInvalidaException;
use App\Exceptions\ProducaoIndustrializadaInvalidaException;
use App\Exceptions\RemessaIndustrializacaoInvalidaException;
use App\Exceptions\ReservaEstoqueInvalidaException;
use App\Exceptions\SaidaEstoqueInvalidaException;
use App\Exceptions\SaldoDestinacaoInsuficienteException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Exceptions\SaldoRecebimentoInsuficienteException;
use App\Exceptions\TransferenciaEstoqueInvalidaException;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Exceptions\AjusteInventarioInvalidoException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\AplicacaoMaterialEstoque;
use App\Models\DestinacaoPlanejadaMaterial;
use App\Models\FamiliaMaterial;
use App\Models\Fornecedor;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemIndustrializacao;
use App\Models\ProdutoIndustrializado;
use App\Models\RecebimentoPedido;
use App\Models\RemessaIndustrializacao;
use App\Models\TransferenciaEstoque;
use App\Models\ContagemInventario;
use App\Models\InventarioAjuste;
use App\Models\InventarioEstoque;
use App\Models\InventarioItem;
use App\Models\ReservaEstoque;
use App\Models\UnidadeEstoque;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use App\Support\Estoque\CoberturaReservas;
use App\Support\Estoque\ConciliacaoAplicacao;
use App\Support\Estoque\ConciliacaoInventario;
use App\Support\Estoque\ConciliacaoDestinacao;
use App\Support\Estoque\DesviosAplicacao;
use App\Support\Estoque\PoliticaAssociacaoMaterial;
use App\Support\Estoque\PoliticaConciliacaoAplicacao;
use App\Support\Estoque\ResolverMaterialDaCadeia;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Ciclo 20, Etapa 20.1 — Fundação do Estoque. Mesmo padrão obra-scoped de
 * ⚡suprimentos.blade.php (public Work $obra injetado via middleware
 * obra.context, garantirPermissao() checando 'estoque.movimentacao').
 *
 * 4 abas: Materiais (catálogo mestre) / Locais (LocalEstoque) /
 * Recebimentos Pendentes (dar entrada a partir de RecebimentoPedido já
 * existente, Ciclo 19) / Movimentações (histórico append-only,
 * somente-leitura).
 *
 * Zero regra de negócio nova aqui — tudo delega pra
 * App\Actions\Estoque\RegistrarEntradaEstoque e
 * App\Support\Estoque\SaldoEstoque, já testados isoladamente.
 */
new class extends Component {
    use ExecutaComTransacaoSegura;

    public Work $obra;

    public string $abaAtiva = 'materiais';

    // ---- Modal Material ----
    public bool $modalMaterialAberto = false;
    public ?string $editandoMaterialId = null;
    public string $materialCodigo = '';
    public string $materialDescricao = '';
    public ?string $materialUnidadeMedidaId = null;
    public ?string $materialFamiliaId = null;
    public string $materialModoRastreabilidade = 'quantitativo';

    // ---- Modal Local ----
    public bool $modalLocalAberto = false;
    public ?string $editandoLocalId = null;
    public string $localNome = '';
    public string $localTipo = 'almoxarifado';

    // ---- Modal Entrada ----
    public bool $modalEntradaAberto = false;
    public ?string $recebimentoEntradaId = null;
    public ?string $entradaLocalId = null;
    public ?float $entradaQuantidade = null;
    public string $entradaData = '';
    public string $entradaCodigoLote = '';
    public string $entradaSerialUnico = '';
    public string $entradaIdentificadorLogistico = '';
    public string $entradaObservacao = '';

    // ---- Modal Associar Material (20.1.CORREÇÃO) ----
    public bool $modalAssociarAberto = false;
    public ?string $itemTakeOffAssociarId = null;
    public string $buscaMaterialAssociar = '';
    public ?string $materialSelecionadoId = null;

    // ---- Modal Destinação Planejada (20.2) ----
    public bool $modalDestinacaoAberto = false;
    public ?string $editandoDestinacaoId = null;
    public ?string $destinacaoPacoteId = null;
    public ?string $destinacaoMaterialId = null;
    public ?string $destinacaoFrenteId = null;
    public ?float $destinacaoQuantidade = null;

    // ---- Modal Reserva de Estoque (20.2 / 20.2.CORREÇÃO) ----
    public bool $modalReservaAberto = false;
    public ?string $reservaDestinacaoId = null;
    public ?string $reservaPacoteId = null;
    public ?string $reservaMaterialId = null;
    public ?string $reservaLocalId = null;
    public ?string $reservaUnidadeId = null;
    public ?float $reservaQuantidade = null;
    public string $reservaObservacao = '';

    // ---- Modal Liberar Reserva (20.2) ----
    public bool $modalLiberarAberto = false;
    public ?string $liberarReservaId = null;
    public string $liberarMotivo = '';

    // ---- Modal Saída de Estoque (20.3) ----
    public bool $modalSaidaAberto = false;
    public ?string $saidaReservaId = null;
    public ?string $saidaPacoteId = null;
    public ?string $saidaMaterialId = null;
    public ?string $saidaLocalId = null;
    public ?string $saidaUnidadeId = null;
    public ?float $saidaQuantidade = null;
    public string $saidaData = '';
    public ?string $saidaFrenteId = null;
    public string $saidaTipoRetirante = 'interno'; // 'interno' | 'externo' — Ciclo 20.3.CORREÇÃO
    public ?string $saidaRetiradoPorId = null;
    public string $saidaRetiradoPorExterno = '';
    public string $saidaObservacao = '';

    // ---- Transferência entre Locais (Ciclo 20, Etapa 20.6) ----
    public bool $modalTransferenciaAberto = false;
    public ?string $transferenciaMaterialId = null;
    public ?string $transferenciaLocalOrigemId = null;
    public ?string $transferenciaLocalDestinoId = null;
    public ?string $transferenciaUnidadeId = null;
    public ?float $transferenciaQuantidade = null;
    public string $transferenciaData = '';
    public string $transferenciaObservacao = '';

    // ---- Conciliação / Aplicação (Ciclo 20, Etapa 20.4) ----
    public ?string $conciliacaoSaidaId = null;
    public bool $modalAplicacaoAberto = false;
    public ?string $aplicacaoEditandoId = null;
    public ?string $aplicacaoFrenteId = null;
    public ?string $aplicacaoPacoteId = null;
    public ?float $aplicacaoQuantidade = null;
    public string $aplicacaoData = '';
    public string $aplicacaoObservacao = '';

    public function mount(Work $obra): void
    {
        $this->obra = $obra;
        $this->entradaData = now()->toDateString();
        $this->saidaData = now()->toDateString();
        $this->aplicacaoData = now()->toDateString();
        $this->transferenciaData = now()->toDateString();
    }

    private function garantirPermissao(string $acao): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.movimentacao', $acao), 403);
    }

    /**
     * Ciclo 20, Etapa 20.4 — slug PRÓPRIO ('estoque.conciliacao'), nunca
     * reaproveita 'estoque.movimentacao' (Seção 40 do pedido: registrar a
     * Saída física e confirmar onde ela foi aplicada são responsabilidades
     * distintas).
     */
    private function garantirPermissaoConciliacao(string $acao): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.conciliacao', $acao), 403);
    }

    // =========================================================
    // Materiais
    // =========================================================

    #[Computed]
    public function materiais()
    {
        // Ciclo 20, Etapa 20.2 — achado real durante teste de performance
        // (100 Materiais): faltava eager-load de unidadeMedida/
        // familiaMaterial, lidas na tabela — nunca disparado antes
        // porque nenhum teste prévio renderizava esta aba (default) com
        // Materiais já cadastrados no banco. Corrigido aqui (bug
        // pré-existente da 20.1, não introduzido nesta etapa).
        $lista = Material::query()->with(['unidadeMedida', 'familiaMaterial'])->orderBy('codigo')->get();
        $saldos = SaldoEstoque::porMateriais($lista->pluck('id')->all());

        return $lista->map(fn (Material $m) => [
            'material' => $m,
            'saldo' => (float) ($saldos[$m->id] ?? 0.0),
        ]);
    }

    #[Computed]
    public function unidadesMedida()
    {
        return UnidadeMedida::where('ativo', true)->orderBy('codigo')->get();
    }

    #[Computed]
    public function familiasMaterial()
    {
        return FamiliaMaterial::where('ativo', true)->orderBy('nome')->get();
    }

    public function abrirModalMaterial(?string $materialId = null): void
    {
        $this->garantirPermissao($materialId ? 'editar' : 'criar');
        $this->resetErrorBag();
        $this->editandoMaterialId = $materialId;

        if ($materialId) {
            $material = Material::findOrFail($materialId);
            $this->materialCodigo = $material->codigo;
            $this->materialDescricao = $material->descricao;
            $this->materialUnidadeMedidaId = $material->unidade_medida_id;
            $this->materialFamiliaId = $material->familia_material_id;
            $this->materialModoRastreabilidade = $material->modo_rastreabilidade->value;
        } else {
            $this->materialCodigo = '';
            $this->materialDescricao = '';
            $this->materialUnidadeMedidaId = null;
            $this->materialFamiliaId = null;
            $this->materialModoRastreabilidade = ModoRastreabilidadeMaterial::Quantitativo->value;
        }

        $this->modalMaterialAberto = true;
    }

    public function fecharModalMaterial(): void
    {
        $this->modalMaterialAberto = false;
    }

    public function salvarMaterial(): void
    {
        $this->garantirPermissao($this->editandoMaterialId ? 'editar' : 'criar');

        $this->validate([
            'materialCodigo' => 'required|string|max:100',
            'materialDescricao' => 'required|string|max:255',
            'materialUnidadeMedidaId' => 'required|exists:unidades_medida,id',
            'materialFamiliaId' => 'nullable|exists:familias_material,id',
            'materialModoRastreabilidade' => 'required|in:' . implode(',', array_map(fn ($c) => $c->value, ModoRastreabilidadeMaterial::cases())),
        ]);

        $this->transacaoSegura(function () {
            $dados = [
                'codigo' => $this->materialCodigo,
                'descricao' => $this->materialDescricao,
                'unidade_medida_id' => $this->materialUnidadeMedidaId,
                'familia_material_id' => $this->materialFamiliaId,
                'modo_rastreabilidade' => $this->materialModoRastreabilidade,
            ];

            if ($this->editandoMaterialId) {
                Material::findOrFail($this->editandoMaterialId)->update($dados);
            } else {
                Material::create($dados + ['ativo' => true]);
            }

            $this->modalMaterialAberto = false;
            unset($this->materiais);
        }, 'Não foi possível salvar o Material.');

        if (! $this->transacaoSeguraFalhou()) {
            $this->dispatch('show-toast', message: 'Material salvo com sucesso.', type: 'success');
        }
    }

    public function alternarStatusMaterial(string $materialId): void
    {
        $this->garantirPermissao('excluir');

        $this->transacaoSegura(function () use ($materialId) {
            $material = Material::findOrFail($materialId);
            $material->update(['ativo' => ! $material->ativo]);
            unset($this->materiais);
        }, 'Não foi possível atualizar o status do Material.');

        if (! $this->transacaoSeguraFalhou()) {
            $this->dispatch('show-toast', message: 'Status do Material atualizado.', type: 'success');
        }
    }

    // =========================================================
    // Locais
    // =========================================================

    #[Computed]
    public function locais()
    {
        return LocalEstoque::where('obra_id', $this->obra->id)->orderBy('nome')->get();
    }

    public function abrirModalLocal(?string $localId = null): void
    {
        $this->garantirPermissao($localId ? 'editar' : 'criar');
        $this->resetErrorBag();
        $this->editandoLocalId = $localId;

        if ($localId) {
            $local = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($localId);
            $this->localNome = $local->nome;
            $this->localTipo = $local->tipo->value;
        } else {
            $this->localNome = '';
            $this->localTipo = TipoLocalEstoque::Almoxarifado->value;
        }

        $this->modalLocalAberto = true;
    }

    public function fecharModalLocal(): void
    {
        $this->modalLocalAberto = false;
    }

    public function salvarLocal(): void
    {
        $this->garantirPermissao($this->editandoLocalId ? 'editar' : 'criar');

        $this->validate([
            'localNome' => 'required|string|max:255',
            'localTipo' => 'required|in:' . implode(',', array_map(fn ($c) => $c->value, TipoLocalEstoque::cases())),
        ]);

        $this->transacaoSegura(function () {
            $dados = ['nome' => $this->localNome, 'tipo' => $this->localTipo];

            if ($this->editandoLocalId) {
                LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->editandoLocalId)->update($dados);
            } else {
                LocalEstoque::create($dados + ['obra_id' => $this->obra->id, 'ativo' => true]);
            }

            $this->modalLocalAberto = false;
            unset($this->locais);
        }, 'Não foi possível salvar o Local de Estoque.');

        if (! $this->transacaoSeguraFalhou()) {
            $this->dispatch('show-toast', message: 'Local de Estoque salvo com sucesso.', type: 'success');
        }
    }

    public function alternarStatusLocal(string $localId): void
    {
        $this->garantirPermissao('excluir');

        $this->transacaoSegura(function () use ($localId) {
            $local = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($localId);
            $local->update(['ativo' => ! $local->ativo]);
            unset($this->locais);
        }, 'Não foi possível atualizar o status do Local de Estoque.');

        if (! $this->transacaoSeguraFalhou()) {
            $this->dispatch('show-toast', message: 'Status do Local de Estoque atualizado.', type: 'success');
        }
    }

    // =========================================================
    // Recebimentos pendentes de incorporação / Entrada
    // =========================================================

    /**
     * Ciclo 20, Etapa 20.3.CORREÇÃO — fecha o Achado B de N+1 real
     * confirmado pela auditoria adversarial (~166 queries pra renderizar
     * com 20 materiais, escalando com o histórico da obra — o badge da
     * navbar chama este computed em TODA troca de aba). Resolução da
     * cadeia (ItemTakeOff) e do saldo incorporado agora em LOTE — 5 + 1
     * queries TOTAIS, nunca por linha — via
     * ResolverMaterialDaCadeia::itemTakeOffEmLote()/SaldoEstoque::
     * incorporadoDeRecebimentos(), ambos novos e exclusivos de listagem
     * (as versões de 1 linha continuam intocadas, ainda usadas por
     * RegistrarEntradaEstoque). Semântica idêntica à anterior — mesmo
     * cálculo de "pendente", mesma fonte de Material, mesma checagem de
     * `podeAlterarMaterial()` também foi batchada
     * (`podeAlterarMaterialEmLote()`, mesma resolução exata do método de
     * 1 item, sem regra paralela) — era a parcela remanescente do
     * hotspot, chamada 1x por linha dentro do mesmo `->map()`.
     */
    #[Computed]
    public function recebimentosPendentes()
    {
        $recebimentos = RecebimentoPedido::query()
            ->whereHas('pedidoCompraItem.pedidoCompra', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->with(['pedidoCompraItem.pedidoCompra'])
            ->latest('recebido_em')
            ->get();

        if ($recebimentos->isEmpty()) {
            return collect();
        }

        $itensTakeOffPorRecebimento = ResolverMaterialDaCadeia::itemTakeOffEmLote($recebimentos);
        $materialIds = $itensTakeOffPorRecebimento->filter()->pluck('material_id')->filter()->unique()->values()->all();
        $materiaisPorId = Material::whereIn('id', $materialIds)->get()->keyBy('id');
        $incorporadoPorRecebimento = SaldoEstoque::incorporadoDeRecebimentos($recebimentos->pluck('id')->all());
        $podeTrocarPorItem = PoliticaAssociacaoMaterial::podeAlterarMaterialEmLote($itensTakeOffPorRecebimento->filter()->values());

        return $recebimentos
            ->map(function (RecebimentoPedido $recebimento) use ($itensTakeOffPorRecebimento, $materiaisPorId, $incorporadoPorRecebimento, $podeTrocarPorItem) {
                $itemTakeOff = $itensTakeOffPorRecebimento->get($recebimento->id);
                $material = $itemTakeOff?->material_id ? $materiaisPorId->get($itemTakeOff->material_id) : null;
                $incorporado = (float) ($incorporadoPorRecebimento[$recebimento->id] ?? 0.0);
                $pendente = round((float) $recebimento->quantidade_recebida - $incorporado, 3);

                return [
                    'recebimento' => $recebimento,
                    'pedido_numero' => $recebimento->pedidoCompraItem?->pedidoCompra?->numero,
                    'descricao' => $recebimento->pedidoCompraItem?->descricao_snapshot,
                    'recebido' => (float) $recebimento->quantidade_recebida,
                    'pendente' => $pendente,
                    'item_take_off_id' => $itemTakeOff?->id,
                    'tem_material' => (bool) $material,
                    'material_codigo' => $material?->codigo,
                    'pode_trocar' => $itemTakeOff ? ($podeTrocarPorItem->get($itemTakeOff->id) ?? false) : false,
                ];
            })
            ->filter(fn ($linha) => $linha['pendente'] > 0.0005)
            ->values();
    }

    public function abrirModalEntrada(string $recebimentoId): void
    {
        $this->garantirPermissao('criar');
        $this->resetErrorBag();
        $this->scanErro = null;
        $this->scanAviso = null;

        $this->recebimentoEntradaId = $recebimentoId;
        $this->entradaLocalId = null;
        $this->entradaQuantidade = null;
        $this->entradaData = now()->toDateString();
        $this->entradaCodigoLote = '';
        $this->entradaSerialUnico = '';
        $this->entradaIdentificadorLogistico = '';
        $this->entradaObservacao = '';
        $this->modalEntradaAberto = true;
    }

    public function fecharModalEntrada(): void
    {
        $this->modalEntradaAberto = false;
    }

    #[Computed]
    public function modoRastreabilidadeDoRecebimentoEmEdicao(): ?string
    {
        if (! $this->recebimentoEntradaId) {
            return null;
        }

        $recebimento = RecebimentoPedido::find($this->recebimentoEntradaId);
        if (! $recebimento) {
            return null;
        }

        $itemTakeOff = ResolverMaterialDaCadeia::itemTakeOff($recebimento);
        $material = $itemTakeOff?->material_id ? Material::find($itemTakeOff->material_id) : null;

        return $material?->modo_rastreabilidade?->value;
    }

    public function confirmarEntrada(): void
    {
        $this->garantirPermissao('criar');

        $this->validate([
            'entradaLocalId' => 'required|exists:locais_estoque,id',
            'entradaQuantidade' => 'required|numeric|min:0.001',
            'entradaData' => 'required|date',
        ]);

        try {
            $recebimento = RecebimentoPedido::findOrFail($this->recebimentoEntradaId);
            $local = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->entradaLocalId);

            app(RegistrarEntradaEstoque::class)->execute(
                $recebimento,
                $local,
                (float) $this->entradaQuantidade,
                Carbon::parse($this->entradaData),
                Auth::user(),
                $this->entradaCodigoLote !== '' ? $this->entradaCodigoLote : null,
                $this->entradaSerialUnico !== '' ? $this->entradaSerialUnico : null,
                $this->entradaIdentificadorLogistico !== '' ? $this->entradaIdentificadorLogistico : null,
                $this->entradaObservacao !== '' ? $this->entradaObservacao : null,
            );

            $this->modalEntradaAberto = false;
            unset($this->recebimentosPendentes, $this->movimentacoes, $this->materiais);
            $this->dispatch('show-toast', message: 'Entrada registrada em estoque.', type: 'success');
        } catch (EntradaEstoqueInvalidaException|SaldoRecebimentoInsuficienteException $e) {
            $this->addError('entradaGeral', $e->getMessage());
        }
    }

    // =========================================================
    // Associação Material ↔ ItemTakeOff (20.1.CORREÇÃO — fecha o
    // Achado C1: até esta correção não existia NENHUM writer real de
    // material_id. Exposta aqui, na aba Recebimentos Pendentes — é o
    // ponto exato onde a ausência de Material bloqueia trabalho real,
    // e o único ponto de associação desta correção (Seção 19 do
    // pedido: não duplicar em duas telas).
    // =========================================================

    #[Computed]
    public function materiaisAtivosParaAssociar()
    {
        $query = Material::where('ativo', true)->orderBy('codigo');

        if ($this->buscaMaterialAssociar !== '') {
            $termo = '%' . $this->buscaMaterialAssociar . '%';
            $query->where(fn ($q) => $q->where('codigo', 'like', $termo)->orWhere('descricao', 'like', $termo));
        }

        return $query->limit(30)->get();
    }

    public function abrirModalAssociarMaterial(string $recebimentoId): void
    {
        $this->garantirPermissao('editar');
        $this->resetErrorBag();

        // Ciclo 20, Etapa 20.9.CORREÇÃO — Achado C1: antes, RecebimentoPedido
        // era resolvido sem nenhum escopo de obra (só o global scope de
        // tenant protegia) — um recebimentoId de OUTRA obra do mesmo tenant
        // era aceito. Mesma cadeia já usada em RegistrarEntradaEstoque pra
        // derivar a obra de um RecebimentoPedido (pedidoCompraItem->pedidoCompra->obra_id).
        $recebimento = RecebimentoPedido::whereHas(
            'pedidoCompraItem.pedidoCompra',
            fn ($q) => $q->where('obra_id', $this->obra->id)
        )->findOrFail($recebimentoId);
        $itemTakeOff = ResolverMaterialDaCadeia::itemTakeOff($recebimento);
        abort_if(! $itemTakeOff, 404, 'Não foi possível localizar o item de Take Off de origem deste recebimento.');

        $this->itemTakeOffAssociarId = $itemTakeOff->id;
        $this->buscaMaterialAssociar = '';
        $this->materialSelecionadoId = $itemTakeOff->material_id;
        $this->modalAssociarAberto = true;
    }

    public function fecharModalAssociarMaterial(): void
    {
        $this->modalAssociarAberto = false;
    }

    public function confirmarAssociarMaterial(): void
    {
        $this->garantirPermissao('editar');

        $this->validate([
            'materialSelecionadoId' => 'required|exists:materiais,id',
        ]);

        try {
            $item = ItemTakeOff::findOrFail($this->itemTakeOffAssociarId);
            $material = Material::findOrFail($this->materialSelecionadoId);

            // Ciclo 20, Etapa 20.9.CORREÇÃO — $this->obra é sempre repassado
            // pra Action validar internamente (defesa em profundidade real:
            // itemTakeOffAssociarId é propriedade PÚBLICA do componente — um
            // payload Livewire manipulado poderia setá-la direto, sem nunca
            // passar por abrirModalAssociarMaterial(); só a validação DENTRO
            // da Action fecha esse vetor de verdade).
            app(AssociarMaterialAoItemTakeOff::class)->execute($this->obra, $item, $material);

            $this->modalAssociarAberto = false;
            unset($this->recebimentosPendentes);
            $this->dispatch('show-toast', message: 'Material associado com sucesso.', type: 'success');
        } catch (AssociacaoMaterialInvalidaException|ItemTakeOffMaterialImutavelException $e) {
            $this->addError('associarGeral', $e->getMessage());
        }
    }

    // =========================================================
    // Destinação Planejada + Reserva de Estoque (Ciclo 20, Etapa 20.2)
    //
    // Zero regra de negócio nova aqui — tudo delega pra
    // App\Actions\Estoque\AtualizarDestinacaoPlanejada/
    // CriarReservaEstoque/LiberarReservaEstoque, já testados
    // isoladamente. Permissão sempre 'editar' (nunca 'criar'/'excluir')
    // — mesmo padrão de planejamento.requisicoes: o slug estoque.reserva
    // só tem 'ver'/'editar' cadastrados em Perfil::REGRAS_ESCRITA.
    // =========================================================

    private function garantirPermissaoReserva(): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.reserva', 'editar'), 403);
    }

    /**
     * Pares Pacote+Material com pelo menos 1 alocação formal na obra —
     * fonte da Seção 38/39 (o que pode receber Destinação). Conciliado
     * em lote via App\Support\Estoque\ConciliacaoDestinacao::porPares()
     * — nunca 1 query por par.
     */
    #[Computed]
    public function paresPacoteMaterial()
    {
        $pares = AlocacaoRequisicaoPacote::query()
            ->whereHas('pacote', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->with(['requisicaoItem.itemTakeOff:id,material_id'])
            ->get()
            ->map(fn (AlocacaoRequisicaoPacote $a) => [
                'item_suprimento_id' => $a->item_suprimento_id,
                'material_id' => $a->requisicaoItem?->itemTakeOff?->material_id,
            ])
            ->filter(fn ($p) => $p['material_id'] !== null)
            ->unique(fn ($p) => $p['item_suprimento_id'] . '|' . $p['material_id'])
            ->values();

        $conciliado = ConciliacaoDestinacao::porPares($pares);

        $pacotes = ItemSuprimento::whereIn('id', $pares->pluck('item_suprimento_id')->unique())->get()->keyBy('id');
        $materiais = Material::whereIn('id', $pares->pluck('material_id')->unique())->get()->keyBy('id');

        return $conciliado->map(fn ($row) => $row + [
            'pacote' => $pacotes[$row['item_suprimento_id']] ?? null,
            'material' => $materiais[$row['material_id']] ?? null,
        ]);
    }

    #[Computed]
    public function frentesTrabalho()
    {
        return FrenteTrabalho::where('obra_id', $this->obra->id)->orderBy('nome')->get();
    }

    #[Computed]
    public function destinacoesPlanejadas()
    {
        // frenteTrabalho() já inclui withTrashed() na própria relação
        // desde a 20.2.CORREÇÃO (Achado B2) — eager-load simples basta.
        $destinacoes = DestinacaoPlanejadaMaterial::where('obra_id', $this->obra->id)
            ->with(['pacote:id,nome,codigo', 'material:id,codigo,descricao', 'frenteTrabalho'])
            ->orderByDesc('created_at')
            ->get();

        $reservadoPorDestinacao = SaldoReserva::porDestinacoes($destinacoes->pluck('id')->all());

        return $destinacoes->map(fn (DestinacaoPlanejadaMaterial $d) => [
            'destinacao' => $d,
            'reservado_ativo' => (float) ($reservadoPorDestinacao[$d->id] ?? 0.0),
        ]);
    }

    #[Computed]
    public function reservasEstoque()
    {
        return ReservaEstoque::where('obra_id', $this->obra->id)
            ->with(['pacote:id,nome,codigo', 'material:id,codigo', 'localEstoque:id,nome', 'unidadeEstoque', 'destinacaoPlanejada.frenteTrabalho', 'autor'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
    }

    /**
     * Unidades (lote/serial) do Material selecionado, já existentes
     * naquele Local, com saldo disponível > 0 — nunca lista uma unidade
     * já totalmente reservada/consumida (Seção 20/29).
     */
    #[Computed]
    public function unidadesDisponiveisParaReserva()
    {
        if (! $this->reservaMaterialId || ! $this->reservaLocalId) {
            return collect();
        }

        $material = Material::find($this->reservaMaterialId);
        if (! $material || $material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Quantitativo) {
            return collect();
        }

        $local = LocalEstoque::find($this->reservaLocalId);
        if (! $local) {
            return collect();
        }

        // Ciclo 20.5.CORREÇÃO: nunca mais filtrar por `local_estoque_id`
        // (fecha o Achado C1 — uma unidade pode ter saldo em mais de um
        // Local ao mesmo tempo). "Disponível" combina o disponível
        // GLOBAL (reservas em qualquer Local) com o saldo FÍSICO
        // escopado a este Local — mesmo critério de
        // App\Actions\Estoque\CriarReservaEstoque.
        return SaldoEstoque::unidadesComPresencaNoLocal($material, $local)
            ->map(fn (UnidadeEstoque $u) => ['unidade' => $u, 'disponivel' => min(
                SaldoReserva::disponivelPorUnidade($u),
                SaldoEstoque::porUnidadeLocal($u, $local)
            )])
            ->filter(fn ($x) => $x['disponivel'] > 0.0005)
            ->values();
    }

    /**
     * Prévia de saldo físico disponível mostrada no modal de Reserva
     * ANTES da confirmação (Seção 40) — mesma fonte que a Action usa
     * pra validar de verdade, nunca um cálculo paralelo.
     */
    #[Computed]
    public function saldoDisponivelPreviewReserva(): ?float
    {
        if (! $this->reservaMaterialId || ! $this->reservaLocalId) {
            return null;
        }

        $material = Material::find($this->reservaMaterialId);
        $local = LocalEstoque::find($this->reservaLocalId);
        if (! $material || ! $local) {
            return null;
        }

        if ($this->reservaUnidadeId) {
            $unidade = UnidadeEstoque::find($this->reservaUnidadeId);

            // Ciclo 20.6.CORREÇÃO: mesma combinação já usada pela Action
            // real (App\Actions\Estoque\CriarReservaEstoque) e pelo
            // computed irmão unidadesDisponiveisParaReserva() — nunca só
            // o disponível GLOBAL isolado, que ignorava o teto físico
            // deste Local específico.
            return $unidade ? min(
                SaldoReserva::disponivelPorUnidade($unidade),
                SaldoEstoque::porUnidadeLocal($unidade, $local)
            ) : null;
        }

        return SaldoReserva::disponivelPorMaterialLocal($material, $local);
    }

    public function abrirModalDestinacao(?string $destinacaoId = null, ?string $pacoteId = null, ?string $materialId = null): void
    {
        $this->garantirPermissaoReserva();
        $this->resetErrorBag();
        $this->editandoDestinacaoId = $destinacaoId;

        if ($destinacaoId) {
            $d = DestinacaoPlanejadaMaterial::where('obra_id', $this->obra->id)->findOrFail($destinacaoId);
            $this->destinacaoPacoteId = $d->item_suprimento_id;
            $this->destinacaoMaterialId = $d->material_id;
            $this->destinacaoFrenteId = $d->frente_trabalho_id;
            $this->destinacaoQuantidade = (float) $d->quantidade_planejada;
        } else {
            $this->destinacaoPacoteId = $pacoteId;
            $this->destinacaoMaterialId = $materialId;
            $this->destinacaoFrenteId = null;
            $this->destinacaoQuantidade = null;
        }

        $this->modalDestinacaoAberto = true;
    }

    public function fecharModalDestinacao(): void
    {
        $this->modalDestinacaoAberto = false;
    }

    public function confirmarDestinacao(): void
    {
        $this->garantirPermissaoReserva();

        $this->validate([
            'destinacaoFrenteId' => 'required|exists:frentes_trabalho,id',
            'destinacaoQuantidade' => 'required|numeric|min:0.001',
        ]);

        try {
            if ($this->editandoDestinacaoId) {
                $d = DestinacaoPlanejadaMaterial::where('obra_id', $this->obra->id)->findOrFail($this->editandoDestinacaoId);
                app(AtualizarDestinacaoPlanejada::class)->alterar($d, (float) $this->destinacaoQuantidade);
            } else {
                $pacote = ItemSuprimento::where('obra_id', $this->obra->id)->findOrFail($this->destinacaoPacoteId);
                $material = Material::findOrFail($this->destinacaoMaterialId);
                $frente = FrenteTrabalho::where('obra_id', $this->obra->id)->findOrFail($this->destinacaoFrenteId);
                app(AtualizarDestinacaoPlanejada::class)->criar($pacote, $material, $frente, (float) $this->destinacaoQuantidade, Auth::user());
            }

            $this->modalDestinacaoAberto = false;
            unset($this->paresPacoteMaterial, $this->destinacoesPlanejadas);
            $this->dispatch('show-toast', message: 'Destinação Planejada salva com sucesso.', type: 'success');
        } catch (DestinacaoPlanejadaInvalidaException|SaldoDestinacaoInsuficienteException|DestinacaoPlanejadaImutavelException $e) {
            $this->addError('destinacaoGeral', $e->getMessage());
        }
    }

    public function excluirDestinacao(string $destinacaoId): void
    {
        $this->garantirPermissaoReserva();

        try {
            $d = DestinacaoPlanejadaMaterial::where('obra_id', $this->obra->id)->findOrFail($destinacaoId);
            app(AtualizarDestinacaoPlanejada::class)->remover($d);
            unset($this->paresPacoteMaterial, $this->destinacoesPlanejadas);
            $this->dispatch('show-toast', message: 'Destinação Planejada excluída.', type: 'success');
        } catch (DestinacaoPlanejadaImutavelException $e) {
            $this->dispatch('show-toast', message: $e->getMessage(), type: 'error');
        }
    }

    /**
     * Ciclo 20.2.CORREÇÃO (fecha o Achado B1) — Pacote agora é sempre
     * conhecido ao abrir o modal: via a Destinação (quando vem de uma
     * linha de Destinação já existente) ou passado direto (quando vem da
     * linha "Demanda por Pacote e Material", que já conhece o par). Não
     * existe mais um caminho de Reserva "totalmente às cegas" — sempre
     * parte de um Pacote+Material já identificados na tela.
     */
    public function abrirModalReserva(?string $destinacaoId = null, ?string $pacoteId = null, ?string $materialId = null): void
    {
        $this->garantirPermissaoReserva();
        $this->resetErrorBag();
        $this->scanErro = null;
        $this->scanAviso = null;

        $this->reservaDestinacaoId = $destinacaoId;
        if ($destinacaoId) {
            $d = DestinacaoPlanejadaMaterial::where('obra_id', $this->obra->id)->findOrFail($destinacaoId);
            $this->reservaPacoteId = $d->item_suprimento_id;
            $this->reservaMaterialId = $d->material_id;
        } else {
            $this->reservaPacoteId = $pacoteId;
            $this->reservaMaterialId = $materialId;
        }

        $this->reservaLocalId = null;
        $this->reservaUnidadeId = null;
        $this->reservaQuantidade = null;
        $this->reservaObservacao = '';
        $this->modalReservaAberto = true;
    }

    public function fecharModalReserva(): void
    {
        $this->modalReservaAberto = false;
    }

    public function updatedReservaMaterialId(): void
    {
        $this->reservaUnidadeId = null;
        unset($this->unidadesDisponiveisParaReserva, $this->saldoDisponivelPreviewReserva);
    }

    public function updatedReservaLocalId(): void
    {
        $this->reservaUnidadeId = null;
        unset($this->unidadesDisponiveisParaReserva, $this->saldoDisponivelPreviewReserva);
    }

    public function updatedReservaUnidadeId(): void
    {
        unset($this->saldoDisponivelPreviewReserva);
    }

    public function confirmarReserva(): void
    {
        $this->garantirPermissaoReserva();

        $this->validate([
            'reservaPacoteId' => 'required|exists:itens_suprimento,id',
            'reservaMaterialId' => 'required|exists:materiais,id',
            'reservaLocalId' => 'required|exists:locais_estoque,id',
            'reservaQuantidade' => 'required|numeric|min:0.001',
        ]);

        try {
            $pacote = ItemSuprimento::where('obra_id', $this->obra->id)->findOrFail($this->reservaPacoteId);
            $material = Material::findOrFail($this->reservaMaterialId);
            $local = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->reservaLocalId);
            $unidade = $this->reservaUnidadeId ? UnidadeEstoque::findOrFail($this->reservaUnidadeId) : null;
            $destinacao = $this->reservaDestinacaoId
                ? DestinacaoPlanejadaMaterial::where('obra_id', $this->obra->id)->findOrFail($this->reservaDestinacaoId)
                : null;

            app(CriarReservaEstoque::class)->execute(
                $pacote,
                $material,
                $local,
                (float) $this->reservaQuantidade,
                $unidade,
                $destinacao,
                Auth::user(),
                $this->reservaObservacao !== '' ? $this->reservaObservacao : null,
            );

            $this->modalReservaAberto = false;
            unset($this->destinacoesPlanejadas, $this->reservasEstoque, $this->materiais);
            $this->dispatch('show-toast', message: 'Reserva de estoque criada com sucesso.', type: 'success');
        } catch (ReservaEstoqueInvalidaException|SaldoFisicoInsuficienteException $e) {
            $this->addError('reservaGeral', $e->getMessage());
        }
    }

    public function abrirModalLiberar(string $reservaId): void
    {
        $this->garantirPermissaoReserva();
        $this->resetErrorBag();
        $this->liberarReservaId = $reservaId;
        $this->liberarMotivo = '';
        $this->modalLiberarAberto = true;
    }

    public function fecharModalLiberar(): void
    {
        $this->modalLiberarAberto = false;
    }

    public function confirmarLiberarReserva(): void
    {
        $this->garantirPermissaoReserva();

        try {
            $reserva = ReservaEstoque::where('obra_id', $this->obra->id)->findOrFail($this->liberarReservaId);
            app(LiberarReservaEstoque::class)->execute($reserva, Auth::user(), $this->liberarMotivo !== '' ? $this->liberarMotivo : null);

            $this->modalLiberarAberto = false;
            unset($this->destinacoesPlanejadas, $this->reservasEstoque);
            $this->dispatch('show-toast', message: 'Reserva liberada.', type: 'success');
        } catch (ReservaEstoqueInvalidaException $e) {
            $this->addError('liberarGeral', $e->getMessage());
        }
    }

    // =========================================================
    // Movimentações (histórico)
    // =========================================================

    #[Computed]
    public function movimentacoes()
    {
        return MovimentacaoEstoque::where('obra_id', $this->obra->id)
            ->with(['material', 'localEstoque', 'unidadeEstoque', 'registradoPor', 'retiradoPorUsuario', 'pacote:id,nome,codigo', 'frenteTrabalho'])
            ->orderByDesc('ocorrido_em')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    // =========================================================
    // Saída de Estoque (Ciclo 20, Etapa 20.3)
    // =========================================================

    #[Computed]
    public function materiaisAtivosParaSaida()
    {
        return Material::where('ativo', true)->orderBy('codigo')->get();
    }

    #[Computed]
    public function locaisAtivosParaSaida()
    {
        return LocalEstoque::where('obra_id', $this->obra->id)->where('ativo', true)->orderBy('nome')->get();
    }

    #[Computed]
    public function pacotesParaSaida()
    {
        return ItemSuprimento::where('obra_id', $this->obra->id)->orderBy('nome')->get();
    }

    /**
     * Reservas Ativas do Material selecionado com saldo ainda pendente
     * de consumo (Seção 39/20) — consumido calculado em LOTE
     * (App\Support\Estoque\SaldoReserva::consumidoPorReservas(), nunca 1
     * SUM por linha, Seção 50).
     */
    #[Computed]
    public function reservasAtivasParaSaida()
    {
        if (! $this->saidaMaterialId) {
            return collect();
        }

        $reservas = ReservaEstoque::where('obra_id', $this->obra->id)
            ->where('material_id', $this->saidaMaterialId)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->with(['pacote:id,nome,codigo', 'localEstoque:id,nome', 'unidadeEstoque', 'destinacaoPlanejada.frenteTrabalho'])
            ->orderBy('created_at')
            ->get();

        $consumidoPorReserva = SaldoReserva::consumidoPorReservas($reservas->pluck('id')->all());

        return $reservas
            ->map(function (ReservaEstoque $r) use ($consumidoPorReserva) {
                $consumido = (float) ($consumidoPorReserva[$r->id] ?? 0.0);

                return [
                    'reserva' => $r,
                    'consumido' => $consumido,
                    'saldo_pendente' => round((float) $r->quantidade - $consumido, 3),
                ];
            })
            ->filter(fn ($x) => $x['saldo_pendente'] > 0.0005)
            ->values();
    }

    /**
     * Unidades (lote/serial) com saldo FÍSICO > 0 (nunca saldo
     * "disponível não reservado" — a Saída sem Reserva é bounded pelo
     * físico total, Seção 15) do Material+Local selecionados.
     */
    #[Computed]
    public function unidadesDisponiveisParaSaida()
    {
        if (! $this->saidaMaterialId || ! $this->saidaLocalId) {
            return collect();
        }

        $material = Material::find($this->saidaMaterialId);
        if (! $material || $material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Quantitativo) {
            return collect();
        }

        $local = LocalEstoque::find($this->saidaLocalId);
        if (! $local) {
            return collect();
        }

        // Ciclo 20.5.CORREÇÃO: saldo escopado a ESTE Local (nunca mais o
        // total global da unidade) — fecha o Achado C1.
        return SaldoEstoque::unidadesComPresencaNoLocal($material, $local)
            ->map(fn (UnidadeEstoque $u) => ['unidade' => $u, 'disponivel' => SaldoEstoque::porUnidadeLocal($u, $local)])
            ->filter(fn ($x) => $x['disponivel'] > 0.0005)
            ->values();
    }

    /**
     * Prévia de saldo FÍSICO (Seção 17) — mesma fonte que a Action usa
     * pra validar de verdade, nunca um cálculo paralelo.
     */
    #[Computed]
    public function saldoFisicoPreviewSaida(): ?float
    {
        if (! $this->saidaMaterialId || ! $this->saidaLocalId) {
            return null;
        }

        $material = Material::find($this->saidaMaterialId);
        $local = LocalEstoque::find($this->saidaLocalId);
        if (! $material || ! $local) {
            return null;
        }

        if ($this->saidaUnidadeId) {
            $unidade = UnidadeEstoque::find($this->saidaUnidadeId);

            // Ciclo 20.6.CORREÇÃO (Achado do fechamento da 20.6): nunca
            // mais o saldo GLOBAL da unidade — sempre escopado ao Local
            // de Saída selecionado, mesma fonte que a Action usa de
            // verdade (RegistrarSaidaEstoque::garantirUnidadeCompativel()).
            return $unidade ? SaldoEstoque::porUnidadeLocal($unidade, $local) : null;
        }

        return SaldoEstoque::porMaterialLocal($material, $local);
    }

    /**
     * Prévia de saldo NÃO reservado (Seção 15) — só usado pra decidir se
     * a UI mostra a advertência de confirmação antes de uma saída SEM
     * Reserva; a Action nunca bloqueia por causa dele.
     */
    #[Computed]
    public function saldoNaoReservadoPreviewSaida(): ?float
    {
        if (! $this->saidaMaterialId || ! $this->saidaLocalId) {
            return null;
        }

        $material = Material::find($this->saidaMaterialId);
        $local = LocalEstoque::find($this->saidaLocalId);
        if (! $material || ! $local) {
            return null;
        }

        if ($this->saidaUnidadeId) {
            $unidade = UnidadeEstoque::find($this->saidaUnidadeId);

            // Ciclo 20.6.CORREÇÃO: uma Reserva é imutavelmente amarrada a
            // um Local desde a criação (20.2.CORREÇÃO) — uma reserva em
            // OUTRO Local nunca deveria reduzir o "não reservado" exibido
            // aqui, mesmo apontando pra mesma Unidade (bobina fracionada).
            return $unidade ? SaldoReserva::disponivelPorUnidadeLocal($unidade, $local) : null;
        }

        return SaldoReserva::disponivelPorMaterialLocal($material, $local);
    }

    /**
     * Seção 15/40: só relevante pra saída SEM Reserva — decide se a UI
     * exige confirmação extra (onclick="confirmarAcao(...)") antes de
     * consumir estoque reservado para outra demanda. Nunca um bloqueio.
     */
    #[Computed]
    public function saidaExcedeSaldoNaoReservado(): bool
    {
        if ($this->saidaReservaId || ! $this->saidaQuantidade) {
            return false;
        }

        $naoReservado = $this->saldoNaoReservadoPreviewSaida;

        if (is_null($naoReservado)) {
            return false;
        }

        return (float) $this->saidaQuantidade > $naoReservado + 0.0005;
    }

    #[Computed]
    public function usuariosDaObraParaSaida()
    {
        return $this->obra->users()->orderBy('first_name')->get();
    }

    public function abrirModalSaida(): void
    {
        $this->garantirPermissao('criar');
        $this->resetErrorBag();
        $this->scanErro = null;
        $this->scanAviso = null;

        $this->saidaReservaId = null;
        $this->saidaPacoteId = null;
        $this->saidaMaterialId = null;
        $this->saidaLocalId = null;
        $this->saidaUnidadeId = null;
        $this->saidaQuantidade = null;
        $this->saidaData = now()->toDateString();
        $this->saidaFrenteId = null;
        $this->saidaTipoRetirante = 'interno';
        $this->saidaRetiradoPorId = null;
        $this->saidaRetiradoPorExterno = '';
        $this->saidaObservacao = '';
        $this->modalSaidaAberto = true;
    }

    public function fecharModalSaida(): void
    {
        $this->modalSaidaAberto = false;
    }

    public function updatedSaidaMaterialId(): void
    {
        $this->saidaUnidadeId = null;
        $this->saidaReservaId = null;
        unset(
            $this->unidadesDisponiveisParaSaida,
            $this->saldoFisicoPreviewSaida,
            $this->saldoNaoReservadoPreviewSaida,
            $this->reservasAtivasParaSaida,
            $this->saidaExcedeSaldoNaoReservado,
        );
    }

    public function updatedSaidaLocalId(): void
    {
        $this->saidaUnidadeId = null;
        unset($this->unidadesDisponiveisParaSaida, $this->saldoFisicoPreviewSaida, $this->saldoNaoReservadoPreviewSaida, $this->saidaExcedeSaldoNaoReservado);
    }

    public function updatedSaidaUnidadeId(): void
    {
        unset($this->saldoFisicoPreviewSaida, $this->saldoNaoReservadoPreviewSaida, $this->saidaExcedeSaldoNaoReservado);
    }

    public function updatedSaidaQuantidade(): void
    {
        unset($this->saidaExcedeSaldoNaoReservado);
    }

    /**
     * Ciclo 20.3.CORREÇÃO — trocar o tipo de retirante limpa o campo do
     * outro tipo, pra nunca deixar os dois preenchidos ao mesmo tempo
     * (a garantia real é sempre a validação server-side da Action —
     * isto é só UX).
     */
    public function updatedSaidaTipoRetirante(): void
    {
        if ($this->saidaTipoRetirante === 'interno') {
            $this->saidaRetiradoPorExterno = '';
        } else {
            $this->saidaRetiradoPorId = null;
        }
    }

    /**
     * Ao selecionar uma Reserva Ativa, Local/Unidade/Pacote são
     * auto-preenchidos e travados a partir dela (evita na origem
     * qualquer tentativa de Local/lote/Pacote errado — Seções 28/29/30 —
     * que App\Actions\Estoque\RegistrarSaidaEstoque ainda revalida
     * server-side de qualquer forma).
     */
    public function updatedSaidaReservaId(): void
    {
        if (! $this->saidaReservaId) {
            unset($this->saidaExcedeSaldoNaoReservado);

            return;
        }

        $reserva = ReservaEstoque::where('obra_id', $this->obra->id)->find($this->saidaReservaId);
        if (! $reserva) {
            $this->saidaReservaId = null;

            return;
        }

        $this->saidaLocalId = $reserva->local_estoque_id;
        $this->saidaUnidadeId = $reserva->unidade_estoque_id;
        $this->saidaPacoteId = $reserva->item_suprimento_id;
        unset($this->unidadesDisponiveisParaSaida, $this->saldoFisicoPreviewSaida, $this->saldoNaoReservadoPreviewSaida, $this->saidaExcedeSaldoNaoReservado);
    }

    public function confirmarSaida(): void
    {
        $this->garantirPermissao('criar');

        $this->validate([
            'saidaMaterialId' => 'required|exists:materiais,id',
            'saidaLocalId' => 'required|exists:locais_estoque,id',
            'saidaQuantidade' => 'required|numeric|min:0.001',
            'saidaData' => 'required|date',
        ]);

        // Ciclo 20.3.CORREÇÃO — checagem de UX (nunca a garantia real,
        // que é sempre a Action): força o campo do tipo NÃO selecionado
        // pra vazio/null antes de validar, então o toggle nunca deixa os
        // dois "vazando" por um wire:model que não foi limpo a tempo.
        if ($this->saidaTipoRetirante === 'interno') {
            $this->saidaRetiradoPorExterno = '';
            $this->validate(['saidaRetiradoPorId' => 'required|exists:users,id'], [
                'saidaRetiradoPorId.required' => 'Selecione o usuário que retirou o material.',
            ]);
        } else {
            $this->saidaRetiradoPorId = null;
            $this->validate(['saidaRetiradoPorExterno' => 'required|string|min:1'], [
                'saidaRetiradoPorExterno.required' => 'Informe o nome de quem retirou o material.',
            ]);
        }

        try {
            $material = Material::findOrFail($this->saidaMaterialId);
            $local = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->saidaLocalId);
            $unidade = $this->saidaUnidadeId ? UnidadeEstoque::findOrFail($this->saidaUnidadeId) : null;
            $reserva = $this->saidaReservaId ? ReservaEstoque::where('obra_id', $this->obra->id)->findOrFail($this->saidaReservaId) : null;
            $pacote = $this->saidaPacoteId ? ItemSuprimento::where('obra_id', $this->obra->id)->findOrFail($this->saidaPacoteId) : null;
            $frente = $this->saidaFrenteId ? FrenteTrabalho::where('obra_id', $this->obra->id)->findOrFail($this->saidaFrenteId) : null;
            $retiradoPor = $this->saidaRetiradoPorId ? $this->obra->users()->find($this->saidaRetiradoPorId) : null;

            app(RegistrarSaidaEstoque::class)->execute(
                $material,
                $local,
                (float) $this->saidaQuantidade,
                Carbon::parse($this->saidaData),
                Auth::user(),
                $reserva,
                $pacote,
                $frente,
                $unidade,
                $retiradoPor,
                $this->saidaRetiradoPorExterno !== '' ? $this->saidaRetiradoPorExterno : null,
                $this->saidaObservacao !== '' ? $this->saidaObservacao : null,
            );

            $this->modalSaidaAberto = false;
            unset($this->movimentacoes, $this->reservasEstoque, $this->reservasAtivasParaSaida, $this->materiais);
            $this->dispatch('show-toast', message: 'Saída registrada em estoque.', type: 'success');
        } catch (SaidaEstoqueInvalidaException|SaldoFisicoInsuficienteException $e) {
            $this->addError('saidaGeral', $e->getMessage());
        }
    }

    // =========================================================
    // Transferência entre Locais de Estoque (Ciclo 20, Etapa 20.6)
    // =========================================================

    public function abrirModalTransferencia(): void
    {
        $this->garantirPermissao('criar');
        $this->resetErrorBag();
        $this->scanErro = null;
        $this->scanAviso = null;

        $this->transferenciaMaterialId = null;
        $this->transferenciaLocalOrigemId = null;
        $this->transferenciaLocalDestinoId = null;
        $this->transferenciaUnidadeId = null;
        $this->transferenciaQuantidade = null;
        $this->transferenciaData = now()->toDateString();
        $this->transferenciaObservacao = '';
        $this->modalTransferenciaAberto = true;
    }

    public function fecharModalTransferencia(): void
    {
        $this->modalTransferenciaAberto = false;
    }

    public function updatedTransferenciaMaterialId(): void
    {
        $this->transferenciaUnidadeId = null;
        unset($this->unidadesDisponiveisParaTransferencia, $this->saldoOrigemPreviewTransferencia);
    }

    public function updatedTransferenciaLocalOrigemId(): void
    {
        $this->transferenciaUnidadeId = null;
        unset($this->unidadesDisponiveisParaTransferencia, $this->saldoOrigemPreviewTransferencia);
    }

    public function updatedTransferenciaUnidadeId(): void
    {
        unset($this->saldoOrigemPreviewTransferencia);
    }

    /**
     * Unidades (lote/serial) do Material selecionado com QUALQUER saldo
     * físico > 0 no Local de ORIGEM — mesmo mecanismo já corrigido em
     * 20.5.CORREÇÃO (nunca `UnidadeEstoque::where('local_estoque_id', ...)`,
     * que assumia localização única).
     */
    #[Computed]
    public function unidadesDisponiveisParaTransferencia()
    {
        if (! $this->transferenciaMaterialId || ! $this->transferenciaLocalOrigemId) {
            return collect();
        }

        $material = Material::find($this->transferenciaMaterialId);
        if (! $material || $material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Quantitativo) {
            return collect();
        }

        $local = LocalEstoque::find($this->transferenciaLocalOrigemId);
        if (! $local) {
            return collect();
        }

        return SaldoEstoque::unidadesComPresencaNoLocal($material, $local)
            ->map(fn (UnidadeEstoque $u) => ['unidade' => $u, 'saldo' => SaldoEstoque::porUnidadeLocal($u, $local)])
            ->filter(fn ($x) => $x['saldo'] > 0.0005)
            ->values();
    }

    /**
     * Prévia de saldo FÍSICO da origem (item 23 do pedido — "mostrar
     * saldo origem antes de confirmar") — mesma fonte que a Action usa
     * pra validar de verdade, sempre escopada ao Local de origem (nunca
     * o saldo global da unidade).
     */
    #[Computed]
    public function saldoOrigemPreviewTransferencia(): ?float
    {
        if (! $this->transferenciaMaterialId || ! $this->transferenciaLocalOrigemId) {
            return null;
        }

        $material = Material::find($this->transferenciaMaterialId);
        $local = LocalEstoque::find($this->transferenciaLocalOrigemId);
        if (! $material || ! $local) {
            return null;
        }

        if ($this->transferenciaUnidadeId) {
            $unidade = UnidadeEstoque::find($this->transferenciaUnidadeId);

            return $unidade ? SaldoEstoque::porUnidadeLocal($unidade, $local) : null;
        }

        return SaldoEstoque::porMaterialLocal($material, $local);
    }

    /**
     * Locais próprios (nunca Terceiro — item 14) disponíveis como
     * destino, excluindo sempre o Local já selecionado como origem
     * (item 5 — origem≠destino já reforçado na UI, nunca só na Action).
     */
    #[Computed]
    public function locaisDestinoParaTransferencia()
    {
        return LocalEstoque::where('obra_id', $this->obra->id)
            ->where('ativo', true)
            ->where('tipo', '!=', TipoLocalEstoque::Terceiro->value)
            ->when($this->transferenciaLocalOrigemId, fn ($q) => $q->where('id', '!=', $this->transferenciaLocalOrigemId))
            ->orderBy('nome')
            ->get();
    }

    /**
     * Locais próprios disponíveis como origem (nunca Terceiro — item 14).
     */
    #[Computed]
    public function locaisOrigemParaTransferencia()
    {
        return LocalEstoque::where('obra_id', $this->obra->id)
            ->where('ativo', true)
            ->where('tipo', '!=', TipoLocalEstoque::Terceiro->value)
            ->orderBy('nome')
            ->get();
    }

    /**
     * Histórico dedicado (item 25 — "exibir timeline: origem, destino,
     * quantidade, data, responsável") — 1 linha por Transferência
     * (identidade de operação), nunca as 2 MovimentacaoEstoque cruas
     * separadas.
     */
    #[Computed]
    public function transferenciasEstoque()
    {
        return TransferenciaEstoque::where('obra_id', $this->obra->id)
            ->with(['material', 'unidadeEstoque', 'localOrigem', 'localDestino', 'registradoPor'])
            ->orderByDesc('ocorrido_em')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    public function confirmarTransferencia(): void
    {
        $this->garantirPermissao('criar');

        $this->validate([
            'transferenciaMaterialId' => 'required|exists:materiais,id',
            'transferenciaLocalOrigemId' => 'required|exists:locais_estoque,id',
            'transferenciaLocalDestinoId' => 'required|exists:locais_estoque,id',
            'transferenciaQuantidade' => 'required|numeric|min:0.001',
            'transferenciaData' => 'required|date',
        ]);

        try {
            $material = Material::findOrFail($this->transferenciaMaterialId);
            $localOrigem = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->transferenciaLocalOrigemId);
            $localDestino = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->transferenciaLocalDestinoId);
            $unidade = $this->transferenciaUnidadeId ? UnidadeEstoque::findOrFail($this->transferenciaUnidadeId) : null;

            app(RegistrarTransferenciaEstoque::class)->execute(
                $material,
                $localOrigem,
                $localDestino,
                (float) $this->transferenciaQuantidade,
                Carbon::parse($this->transferenciaData),
                Auth::user(),
                $unidade,
                $this->transferenciaObservacao !== '' ? $this->transferenciaObservacao : null,
            );

            $this->modalTransferenciaAberto = false;
            unset($this->movimentacoes, $this->transferenciasEstoque);
            $this->dispatch('show-toast', message: 'Transferência registrada.', type: 'success');
        } catch (TransferenciaEstoqueInvalidaException|SaldoFisicoInsuficienteException $e) {
            $this->addError('transferenciaGeral', $e->getMessage());
        }
    }

    // =========================================================
    // Conciliação / Aplicação (Ciclo 20, Etapa 20.4)
    // =========================================================

    /**
     * Saídas com pendência de conciliação > 0, da obra atual — total
     * aplicado calculado em LOTE (App\Support\Estoque\
     * PoliticaConciliacaoAplicacao::totalAplicadoEmLote(), nunca 1 SUM
     * por linha, Seção 51). `desviado` também em lote via
     * App\Support\Estoque\DesviosAplicacao::porSaidasEmLote().
     */
    #[Computed]
    public function saidasComPendencia()
    {
        $saidas = MovimentacaoEstoque::where('obra_id', $this->obra->id)
            ->where('tipo', TipoMovimentacaoEstoque::Saida->value)
            ->with(['material', 'localEstoque', 'unidadeEstoque', 'pacote:id,nome,codigo', 'frenteTrabalho', 'reservaEstoque.destinacaoPlanejada.frenteTrabalho'])
            ->orderByDesc('ocorrido_em')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $aplicadoPorSaida = PoliticaConciliacaoAplicacao::totalAplicadoEmLote($saidas->pluck('id')->all());
        $desviosPorSaida = DesviosAplicacao::porSaidasEmLote($saidas);

        return $saidas
            ->map(function (MovimentacaoEstoque $saida) use ($aplicadoPorSaida, $desviosPorSaida) {
                $aplicado = (float) ($aplicadoPorSaida[$saida->id] ?? 0.0);
                $pendente = round((float) $saida->quantidade - $aplicado, 3);

                return [
                    'saida' => $saida,
                    'aplicado' => $aplicado,
                    'pendente' => $pendente,
                    'desvio' => $desviosPorSaida->get($saida->id),
                ];
            })
            ->filter(fn (array $x) => $x['pendente'] > 0.0005)
            ->values();
    }

    #[Computed]
    public function saidaEmConciliacao(): ?MovimentacaoEstoque
    {
        if (! $this->conciliacaoSaidaId) {
            return null;
        }

        return MovimentacaoEstoque::where('obra_id', $this->obra->id)
            ->with(['material', 'localEstoque', 'unidadeEstoque', 'pacote:id,nome,codigo', 'reservaEstoque.destinacaoPlanejada.frenteTrabalho'])
            ->find($this->conciliacaoSaidaId);
    }

    #[Computed]
    public function aplicacoesDaSaidaEmConciliacao()
    {
        if (! $this->conciliacaoSaidaId) {
            return collect();
        }

        return AplicacaoMaterialEstoque::where('movimentacao_estoque_id', $this->conciliacaoSaidaId)
            ->with(['frenteTrabalho', 'pacote:id,nome,codigo', 'registradoPor', 'atualizadoPor'])
            ->orderBy('aplicado_em')
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function pendenteDaSaidaEmConciliacao(): ?float
    {
        $saida = $this->saidaEmConciliacao;

        return $saida ? PoliticaConciliacaoAplicacao::pendente($saida) : null;
    }

    #[Computed]
    public function saidaEmConciliacaoFechada(): bool
    {
        $saida = $this->saidaEmConciliacao;

        return $saida ? PoliticaConciliacaoAplicacao::saidaEstaFechada($saida) : false;
    }

    #[Computed]
    public function desvioDaSaidaEmConciliacao(): ?array
    {
        $saida = $this->saidaEmConciliacao;

        return $saida ? DesviosAplicacao::porSaida($saida) : null;
    }

    #[Computed]
    public function frentesParaAplicacao()
    {
        return FrenteTrabalho::where('obra_id', $this->obra->id)->orderBy('nome')->get();
    }

    #[Computed]
    public function pacotesParaAplicacao()
    {
        return ItemSuprimento::where('obra_id', $this->obra->id)->orderBy('nome')->get();
    }

    /**
     * Dashboard mínimo de Cobertura/Déficit (Seção 43/44) — por par
     * Material+Local com ao menos uma Reserva Ativa nesta obra. Nunca
     * soma unidades incompatíveis (agrega por Material+Local, nunca
     * cross-Material).
     */
    #[Computed]
    public function coberturaDeficitPorObra()
    {
        $pares = ReservaEstoque::where('obra_id', $this->obra->id)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->with(['material:id,codigo,descricao', 'localEstoque:id,nome'])
            ->get()
            ->unique(fn (ReservaEstoque $r) => $r->material_id . '|' . $r->local_estoque_id)
            ->map(fn (ReservaEstoque $r) => ['material' => $r->material, 'local' => $r->localEstoque])
            ->values();

        return CoberturaReservas::porPares($pares)
            ->map(function (array $linha) use ($pares) {
                $par = $pares->first(fn (array $p) => $p['material']->id === $linha['material_id'] && $p['local']->id === $linha['local_estoque_id']);

                return $linha + ['material' => $par['material'], 'local' => $par['local']];
            })
            ->filter(fn (array $x) => $x['deficit'] > 0.0005)
            ->values();
    }

    public function abrirModalConciliacao(string $saidaId): void
    {
        $this->garantirPermissaoConciliacao('criar');
        $this->conciliacaoSaidaId = $saidaId;
        $this->resetarFormAplicacao();
        unset($this->saidaEmConciliacao, $this->aplicacoesDaSaidaEmConciliacao, $this->pendenteDaSaidaEmConciliacao, $this->saidaEmConciliacaoFechada, $this->desvioDaSaidaEmConciliacao);
        $this->modalAplicacaoAberto = true;
    }

    public function fecharModalConciliacao(): void
    {
        $this->modalAplicacaoAberto = false;
        $this->conciliacaoSaidaId = null;
        $this->resetarFormAplicacao();
    }

    private function resetarFormAplicacao(): void
    {
        $this->aplicacaoEditandoId = null;
        $this->aplicacaoFrenteId = null;
        $this->aplicacaoPacoteId = null;
        $this->aplicacaoQuantidade = null;
        $this->aplicacaoData = now()->toDateString();
        $this->aplicacaoObservacao = '';
        $this->resetErrorBag(['aplicacaoGeral', 'aplicacaoFrenteId', 'aplicacaoQuantidade', 'aplicacaoData']);
    }

    public function editarLinhaAplicacao(string $aplicacaoId): void
    {
        $this->garantirPermissaoConciliacao('editar');
        $aplicacao = AplicacaoMaterialEstoque::where('movimentacao_estoque_id', $this->conciliacaoSaidaId)->findOrFail($aplicacaoId);

        $this->aplicacaoEditandoId = $aplicacao->id;
        $this->aplicacaoFrenteId = $aplicacao->frente_trabalho_id;
        $this->aplicacaoPacoteId = $aplicacao->item_suprimento_id;
        $this->aplicacaoQuantidade = (float) $aplicacao->quantidade;
        $this->aplicacaoData = $aplicacao->aplicado_em->toDateString();
        $this->aplicacaoObservacao = (string) $aplicacao->observacao;
    }

    public function cancelarEdicaoAplicacao(): void
    {
        $this->resetarFormAplicacao();
    }

    public function confirmarAplicacao(): void
    {
        $this->garantirPermissaoConciliacao($this->aplicacaoEditandoId ? 'editar' : 'criar');

        $this->validate([
            'aplicacaoFrenteId' => 'required|exists:frentes_trabalho,id',
            'aplicacaoQuantidade' => 'required|numeric|min:0.001',
            'aplicacaoData' => 'required|date',
        ]);

        try {
            $saida = MovimentacaoEstoque::where('obra_id', $this->obra->id)->findOrFail($this->conciliacaoSaidaId);
            $frente = FrenteTrabalho::where('obra_id', $this->obra->id)->findOrFail($this->aplicacaoFrenteId);
            $pacote = $this->aplicacaoPacoteId ? ItemSuprimento::where('obra_id', $this->obra->id)->findOrFail($this->aplicacaoPacoteId) : null;
            $observacao = $this->aplicacaoObservacao !== '' ? $this->aplicacaoObservacao : null;

            if ($this->aplicacaoEditandoId) {
                $aplicacao = AplicacaoMaterialEstoque::where('movimentacao_estoque_id', $saida->id)->findOrFail($this->aplicacaoEditandoId);
                app(AtualizarAplicacaoMaterialEstoque::class)->execute(
                    $aplicacao, $frente, (float) $this->aplicacaoQuantidade, Carbon::parse($this->aplicacaoData), Auth::user(), $pacote, $observacao,
                );
            } else {
                app(RegistrarAplicacaoMaterialEstoque::class)->execute(
                    $saida, $frente, (float) $this->aplicacaoQuantidade, Carbon::parse($this->aplicacaoData), Auth::user(), $pacote, $observacao,
                );
            }

            $this->resetarFormAplicacao();
            unset($this->saidasComPendencia, $this->aplicacoesDaSaidaEmConciliacao, $this->pendenteDaSaidaEmConciliacao, $this->saidaEmConciliacaoFechada, $this->desvioDaSaidaEmConciliacao, $this->coberturaDeficitPorObra);
            $this->dispatch('show-toast', message: 'Aplicação registrada.', type: 'success');
        } catch (AplicacaoConciliacaoInvalidaException|AplicacaoConciliacaoFechadaException $e) {
            $this->addError('aplicacaoGeral', $e->getMessage());
        }
    }

    public function excluirLinhaAplicacao(string $aplicacaoId): void
    {
        $this->garantirPermissaoConciliacao('excluir');

        try {
            $aplicacao = AplicacaoMaterialEstoque::where('movimentacao_estoque_id', $this->conciliacaoSaidaId)->findOrFail($aplicacaoId);
            app(RemoverAplicacaoMaterialEstoque::class)->execute($aplicacao);

            unset($this->saidasComPendencia, $this->aplicacoesDaSaidaEmConciliacao, $this->pendenteDaSaidaEmConciliacao, $this->saidaEmConciliacaoFechada, $this->desvioDaSaidaEmConciliacao, $this->coberturaDeficitPorObra);
            $this->dispatch('show-toast', message: 'Aplicação removida.', type: 'success');
        } catch (AplicacaoConciliacaoFechadaException $e) {
            $this->addError('aplicacaoGeral', $e->getMessage());
        }
    }

    // =========================================================
    // Industrialização em Terceiros (Ciclo 20, Etapa 20.5)
    // =========================================================

    // ---- Industrialização em Terceiros (Ciclo 20, Etapa 20.5) ----
    public ?string $ordemIndustrDetalheId = null;
    public bool $modalOrdemIndustrAberto = false;
    public ?string $ordemIndustrEditandoId = null;
    public ?string $ordemIndustrFornecedorId = null;
    public ?string $ordemIndustrLocalTerceiroId = null;
    public ?string $ordemIndustrPacoteId = null;
    public string $ordemIndustrObservacao = '';

    public bool $modalProdutoIndustrAberto = false;
    public ?string $produtoIndustrMaterialId = null;
    public ?string $produtoIndustrDocumentoRevisaoId = null;
    public ?float $produtoIndustrQuantidadePrevista = null;
    public string $produtoIndustrObservacao = '';

    public bool $modalRemessaIndustrAberto = false;
    public ?string $remessaIndustrMaterialId = null;
    public ?string $remessaIndustrLocalProprioId = null;
    public string $remessaIndustrDirecao = 'envio';
    public ?float $remessaIndustrQuantidade = null;
    public string $remessaIndustrData = '';
    public ?string $remessaIndustrUnidadeId = null;
    public string $remessaIndustrObservacao = '';

    public bool $modalProducaoIndustrAberto = false;
    public ?string $producaoIndustrProdutoId = null;
    public ?float $producaoIndustrQuantidade = null;
    public string $producaoIndustrData = '';
    public string $producaoIndustrCodigoLote = '';
    public string $producaoIndustrSerialUnico = '';
    public string $producaoIndustrObservacao = '';

    public bool $modalConsumoIndustrAberto = false;
    public ?string $consumoIndustrProdutoId = null;
    public ?string $consumoIndustrRemessaId = null;
    public ?float $consumoIndustrQuantidade = null;
    public string $consumoIndustrData = '';
    public string $consumoIndustrObservacao = '';

    public bool $modalEntregaIndustrAberto = false;
    public ?string $entregaIndustrProdutoId = null;
    public ?float $entregaIndustrQuantidade = null;
    public string $entregaIndustrModalidade = 'retorno_estoque_obra';
    public ?string $entregaIndustrLocalDestinoId = null;
    public ?string $entregaIndustrFrenteId = null;
    public string $entregaIndustrTipoRetirante = 'interno';
    public ?string $entregaIndustrRetiradoPorId = null;
    public string $entregaIndustrRetiradoPorExterno = '';
    public string $entregaIndustrData = '';
    public string $entregaIndustrObservacao = '';

    private function garantirPermissaoIndustrializacao(string $acao): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.industrializacao', $acao), 403);
    }

    #[Computed]
    public function ordensIndustrializacao()
    {
        return \App\Models\OrdemIndustrializacao::where('obra_id', $this->obra->id)
            ->with(['fornecedor:id,nome', 'localTerceiro:id,nome'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
    }

    #[Computed]
    public function fornecedoresParaIndustr()
    {
        return \App\Models\Fornecedor::where('obra_id', $this->obra->id)->orderBy('nome')->get();
    }

    #[Computed]
    public function locaisTerceiroParaIndustr()
    {
        $query = LocalEstoque::where('obra_id', $this->obra->id)
            ->where('tipo', TipoLocalEstoque::Terceiro->value)
            ->where('ativo', true);

        if ($this->ordemIndustrFornecedorId) {
            $query->where('fornecedor_id', $this->ordemIndustrFornecedorId);
        }

        return $query->orderBy('nome')->get();
    }

    #[Computed]
    public function locaisProprioParaIndustr()
    {
        return LocalEstoque::where('obra_id', $this->obra->id)
            ->where('tipo', '!=', TipoLocalEstoque::Terceiro->value)
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();
    }

    #[Computed]
    public function pacotesParaIndustr()
    {
        return ItemSuprimento::where('obra_id', $this->obra->id)->orderBy('nome')->get();
    }

    #[Computed]
    public function materiaisParaIndustr()
    {
        return Material::where('ativo', true)->orderBy('codigo')->get();
    }

    #[Computed]
    public function documentosRevisaoParaIndustr()
    {
        return \App\Models\DocumentoEngenhariaRevisao::whereHas('documento', fn ($q) => $q->where('obra_id', $this->obra->id))
            ->vigentes()
            ->with('documento:id,codigo,descricao')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function ordemIndustrDetalhe(): ?\App\Models\OrdemIndustrializacao
    {
        if (! $this->ordemIndustrDetalheId) {
            return null;
        }

        return \App\Models\OrdemIndustrializacao::where('obra_id', $this->obra->id)
            ->with(['fornecedor', 'localTerceiro', 'pacote:id,nome,codigo', 'produtos.material', 'produtos.documentoRevisao.documento', 'remessas.material', 'remessas.unidadeEstoque'])
            ->find($this->ordemIndustrDetalheId);
    }

    #[Computed]
    public function produtosDaOrdemDetalheComSaldo()
    {
        $ordem = $this->ordemIndustrDetalhe;
        if (! $ordem) {
            return collect();
        }

        $produtos = $ordem->produtos;
        $saldos = \App\Support\Industrializacao\SaldoProdutoIndustrializado::porProdutosEmLote($produtos->pluck('id')->all());

        return $produtos->map(function ($produto) use ($saldos) {
            $saldo = $saldos->get($produto->id, ['produzido' => 0.0, 'entregue' => 0.0, 'saldo_pronto' => 0.0]);

            return ['produto' => $produto] + $saldo;
        });
    }

    #[Computed]
    public function remessasEnvioParaConsumo()
    {
        $ordem = $this->ordemIndustrDetalhe;
        if (! $ordem) {
            return collect();
        }

        return $ordem->remessas
            ->where('direcao', DirecaoRemessaIndustrializacao::Envio)
            ->map(fn ($r) => ['remessa' => $r, 'pendente' => \App\Support\Industrializacao\GenealogiaIndustrializacao::pendenteDeConsumo($r)])
            ->filter(fn ($x) => $x['pendente'] > 0.0005)
            ->values();
    }

    #[Computed]
    public function frentesParaIndustr()
    {
        return FrenteTrabalho::where('obra_id', $this->obra->id)->orderBy('nome')->get();
    }

    #[Computed]
    public function usuariosDaObraParaIndustr()
    {
        return $this->obra->users()->where('ativo', true)->orderBy('first_name')->get();
    }

    #[Computed]
    public function unidadesDisponiveisParaRemessa()
    {
        if (! $this->remessaIndustrMaterialId || ! $this->remessaIndustrLocalProprioId) {
            return collect();
        }

        $localOrigemId = $this->remessaIndustrDirecao === 'envio'
            ? $this->remessaIndustrLocalProprioId
            : $this->ordemIndustrDetalhe?->local_terceiro_id;

        if (! $localOrigemId) {
            return collect();
        }

        $material = Material::find($this->remessaIndustrMaterialId);
        $localOrigem = LocalEstoque::find($localOrigemId);
        if (! $material || ! $localOrigem) {
            return collect();
        }

        // Ciclo 20.5.CORREÇÃO: saldo escopado ao Local de ORIGEM desta
        // remessa (nunca mais o total global da unidade) — fecha o
        // Achado C1: uma remessa parcial anterior pode já ter deixado
        // parte do saldo desta mesma unidade em outro Local.
        return SaldoEstoque::unidadesComPresencaNoLocal($material, $localOrigem)
            ->map(fn ($u) => ['unidade' => $u, 'saldo' => SaldoEstoque::porUnidadeLocal($u, $localOrigem)])
            ->filter(fn ($x) => $x['saldo'] > 0.0005)
            ->values();
    }

    #[Computed]
    public function saldoMateriaPrimaOrdemDetalhe()
    {
        $ordem = $this->ordemIndustrDetalhe;
        if (! $ordem) {
            return collect();
        }

        $materiaisIds = $ordem->remessas->pluck('material_id')->unique();

        return $materiaisIds->map(function ($materialId) use ($ordem) {
            $material = Material::find($materialId);

            return ['material' => $material] + \App\Support\Industrializacao\SaldoMateriaPrimaIndustrializacao::porOrdemMaterial($ordem, $material);
        });
    }

    public function abrirModalOrdemIndustr(): void
    {
        $this->garantirPermissaoIndustrializacao('editar');
        $this->resetarFormOrdemIndustr();
        $this->modalOrdemIndustrAberto = true;
    }

    public function fecharModalOrdemIndustr(): void
    {
        $this->modalOrdemIndustrAberto = false;
        $this->resetarFormOrdemIndustr();
    }

    private function resetarFormOrdemIndustr(): void
    {
        $this->ordemIndustrFornecedorId = null;
        $this->ordemIndustrLocalTerceiroId = null;
        $this->ordemIndustrPacoteId = null;
        $this->ordemIndustrObservacao = '';
        $this->resetErrorBag(['ordemIndustrGeral']);
    }

    public function confirmarCriarOrdemIndustr(): void
    {
        $this->garantirPermissaoIndustrializacao('editar');

        $this->validate([
            'ordemIndustrFornecedorId' => 'required|exists:fornecedores,id',
            'ordemIndustrLocalTerceiroId' => 'required|exists:locais_estoque,id',
        ]);

        try {
            $fornecedor = Fornecedor::where('obra_id', $this->obra->id)->findOrFail($this->ordemIndustrFornecedorId);
            $local = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->ordemIndustrLocalTerceiroId);
            $pacote = $this->ordemIndustrPacoteId ? ItemSuprimento::where('obra_id', $this->obra->id)->findOrFail($this->ordemIndustrPacoteId) : null;

            $ordem = app(\App\Actions\Estoque\CriarOrdemIndustrializacao::class)->execute(
                $this->obra, $fornecedor, $local, Auth::user(), $pacote,
                $this->ordemIndustrObservacao !== '' ? $this->ordemIndustrObservacao : null,
            );

            $this->fecharModalOrdemIndustr();
            unset($this->ordensIndustrializacao);
            $this->ordemIndustrDetalheId = $ordem->id;
            $this->dispatch('show-toast', message: 'Ordem de Industrialização criada.', type: 'success');
        } catch (OrdemIndustrializacaoInvalidaException $e) {
            $this->addError('ordemIndustrGeral', $e->getMessage());
        }
    }

    public function abrirDetalheOrdemIndustr(string $ordemId): void
    {
        $this->garantirPermissaoIndustrializacao('ver');
        $this->ordemIndustrDetalheId = $ordemId;
        unset($this->ordemIndustrDetalhe, $this->produtosDaOrdemDetalheComSaldo, $this->remessasEnvioParaConsumo, $this->saldoMateriaPrimaOrdemDetalhe);
    }

    public function fecharDetalheOrdemIndustr(): void
    {
        $this->ordemIndustrDetalheId = null;
    }

    public function abrirModalProdutoIndustr(): void
    {
        $this->garantirPermissaoIndustrializacao('editar');
        $this->produtoIndustrMaterialId = null;
        $this->produtoIndustrDocumentoRevisaoId = null;
        $this->produtoIndustrQuantidadePrevista = null;
        $this->produtoIndustrObservacao = '';
        $this->resetErrorBag(['produtoIndustrGeral']);
        $this->modalProdutoIndustrAberto = true;
    }

    public function fecharModalProdutoIndustr(): void
    {
        $this->modalProdutoIndustrAberto = false;
    }

    public function confirmarAdicionarProdutoIndustr(): void
    {
        $this->garantirPermissaoIndustrializacao('editar');

        $this->validate([
            'produtoIndustrMaterialId' => 'required|exists:materiais,id',
            'produtoIndustrQuantidadePrevista' => 'required|numeric|min:0.001',
        ]);

        try {
            $ordem = $this->ordemIndustrDetalhe;
            if (! $ordem) {
                $this->addError('produtoIndustrGeral', 'Ordem não encontrada ou você não tem mais acesso a ela.');

                return;
            }
            $material = Material::findOrFail($this->produtoIndustrMaterialId);
            $revisao = $this->produtoIndustrDocumentoRevisaoId ? \App\Models\DocumentoEngenhariaRevisao::findOrFail($this->produtoIndustrDocumentoRevisaoId) : null;

            app(AtualizarRascunhoOrdemIndustrializacao::class)->adicionarProduto(
                $ordem, $material, (float) $this->produtoIndustrQuantidadePrevista, Auth::user(), $revisao,
                $this->produtoIndustrObservacao !== '' ? $this->produtoIndustrObservacao : null,
            );

            $this->fecharModalProdutoIndustr();
            unset($this->ordemIndustrDetalhe, $this->produtosDaOrdemDetalheComSaldo);
            $this->dispatch('show-toast', message: 'Produto adicionado à Ordem.', type: 'success');
        } catch (OrdemIndustrializacaoImutavelException|OrdemIndustrializacaoInvalidaException $e) {
            $this->addError('produtoIndustrGeral', $e->getMessage());
        }
    }

    public function confirmarEmitirOrdemIndustr(): void
    {
        $this->garantirPermissaoIndustrializacao('editar');

        try {
            $ordem = $this->ordemIndustrDetalhe;
            if (! $ordem) {
                $this->addError('ordemIndustrDetalheGeral', 'Ordem não encontrada ou você não tem mais acesso a ela.');

                return;
            }

            app(EmitirOrdemIndustrializacao::class)->execute($ordem, Auth::user());

            unset($this->ordemIndustrDetalhe, $this->ordensIndustrializacao);
            $this->dispatch('show-toast', message: 'Ordem de Industrialização emitida.', type: 'success');
        } catch (OrdemIndustrializacaoInvalidaException $e) {
            $this->addError('ordemIndustrDetalheGeral', $e->getMessage());
        }
    }

    public function abrirModalRemessaIndustr(string $direcao = 'envio'): void
    {
        $this->garantirPermissaoIndustrializacao('criar');
        $this->remessaIndustrMaterialId = null;
        $this->remessaIndustrLocalProprioId = null;
        $this->remessaIndustrDirecao = $direcao;
        $this->remessaIndustrQuantidade = null;
        $this->remessaIndustrData = now()->toDateString();
        $this->remessaIndustrUnidadeId = null;
        $this->remessaIndustrObservacao = '';
        $this->resetErrorBag(['remessaIndustrGeral']);
        $this->modalRemessaIndustrAberto = true;
    }

    public function fecharModalRemessaIndustr(): void
    {
        $this->modalRemessaIndustrAberto = false;
    }

    public function confirmarRemessaIndustr(): void
    {
        $this->garantirPermissaoIndustrializacao('criar');

        $this->validate([
            'remessaIndustrMaterialId' => 'required|exists:materiais,id',
            'remessaIndustrLocalProprioId' => 'required|exists:locais_estoque,id',
            'remessaIndustrQuantidade' => 'required|numeric|min:0.001',
            'remessaIndustrData' => 'required|date',
        ]);

        try {
            $ordem = $this->ordemIndustrDetalhe;
            if (! $ordem) {
                $this->addError('remessaIndustrGeral', 'Ordem não encontrada ou você não tem mais acesso a ela.');

                return;
            }
            $material = Material::findOrFail($this->remessaIndustrMaterialId);
            $localProprio = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->remessaIndustrLocalProprioId);
            $unidade = $this->remessaIndustrUnidadeId ? UnidadeEstoque::findOrFail($this->remessaIndustrUnidadeId) : null;
            $direcao = DirecaoRemessaIndustrializacao::from($this->remessaIndustrDirecao);

            app(RegistrarRemessaIndustrializacao::class)->execute(
                $ordem, $material, (float) $this->remessaIndustrQuantidade, $direcao, $localProprio,
                Carbon::parse($this->remessaIndustrData), Auth::user(), $unidade,
                $this->remessaIndustrObservacao !== '' ? $this->remessaIndustrObservacao : null,
            );

            $this->fecharModalRemessaIndustr();
            unset($this->ordemIndustrDetalhe, $this->saldoMateriaPrimaOrdemDetalhe, $this->remessasEnvioParaConsumo);
            $this->dispatch('show-toast', message: 'Remessa registrada.', type: 'success');
        } catch (RemessaIndustrializacaoInvalidaException $e) {
            $this->addError('remessaIndustrGeral', $e->getMessage());
        }
    }

    public function abrirModalProducaoIndustr(string $produtoId): void
    {
        $this->garantirPermissaoIndustrializacao('criar');
        $this->producaoIndustrProdutoId = $produtoId;
        $this->producaoIndustrQuantidade = null;
        $this->producaoIndustrData = now()->toDateString();
        $this->producaoIndustrCodigoLote = '';
        $this->producaoIndustrSerialUnico = '';
        $this->producaoIndustrObservacao = '';
        $this->resetErrorBag(['producaoIndustrGeral']);
        $this->modalProducaoIndustrAberto = true;
    }

    public function fecharModalProducaoIndustr(): void
    {
        $this->modalProducaoIndustrAberto = false;
    }

    public function confirmarProducaoIndustr(): void
    {
        $this->garantirPermissaoIndustrializacao('criar');

        $this->validate([
            'producaoIndustrQuantidade' => 'required|numeric|min:0.001',
            'producaoIndustrData' => 'required|date',
        ]);

        try {
            $produto = ProdutoIndustrializado::where('obra_id', $this->obra->id)->findOrFail($this->producaoIndustrProdutoId);

            app(RegistrarProducaoIndustrializada::class)->execute(
                $produto, (float) $this->producaoIndustrQuantidade, Carbon::parse($this->producaoIndustrData), Auth::user(),
                $this->producaoIndustrCodigoLote !== '' ? $this->producaoIndustrCodigoLote : null,
                $this->producaoIndustrSerialUnico !== '' ? $this->producaoIndustrSerialUnico : null,
                null,
                $this->producaoIndustrObservacao !== '' ? $this->producaoIndustrObservacao : null,
            );

            $this->fecharModalProducaoIndustr();
            unset($this->ordemIndustrDetalhe, $this->produtosDaOrdemDetalheComSaldo);
            $this->dispatch('show-toast', message: 'Produção registrada.', type: 'success');
        } catch (\App\Exceptions\ProducaoIndustrializadaInvalidaException $e) {
            $this->addError('producaoIndustrGeral', $e->getMessage());
        }
    }

    public function abrirModalConsumoIndustr(string $produtoId): void
    {
        $this->garantirPermissaoIndustrializacao('criar');
        $this->consumoIndustrProdutoId = $produtoId;
        $this->consumoIndustrRemessaId = null;
        $this->consumoIndustrQuantidade = null;
        $this->consumoIndustrData = now()->toDateString();
        $this->consumoIndustrObservacao = '';
        $this->resetErrorBag(['consumoIndustrGeral']);
        $this->modalConsumoIndustrAberto = true;
    }

    public function fecharModalConsumoIndustr(): void
    {
        $this->modalConsumoIndustrAberto = false;
    }

    public function confirmarConsumoIndustr(): void
    {
        $this->garantirPermissaoIndustrializacao('criar');

        $this->validate([
            'consumoIndustrRemessaId' => 'required|exists:remessas_industrializacao,id',
            'consumoIndustrQuantidade' => 'required|numeric|min:0.001',
            'consumoIndustrData' => 'required|date',
        ]);

        try {
            $produto = ProdutoIndustrializado::where('obra_id', $this->obra->id)->findOrFail($this->consumoIndustrProdutoId);
            $remessa = RemessaIndustrializacao::where('obra_id', $this->obra->id)->findOrFail($this->consumoIndustrRemessaId);

            app(RegistrarConsumoIndustrializacao::class)->execute(
                $produto, $remessa, (float) $this->consumoIndustrQuantidade, Carbon::parse($this->consumoIndustrData), Auth::user(),
                $this->consumoIndustrObservacao !== '' ? $this->consumoIndustrObservacao : null,
            );

            $this->fecharModalConsumoIndustr();
            unset($this->ordemIndustrDetalhe, $this->saldoMateriaPrimaOrdemDetalhe, $this->remessasEnvioParaConsumo);
            $this->dispatch('show-toast', message: 'Consumo registrado.', type: 'success');
        } catch (ConsumoIndustrializacaoInvalidaException $e) {
            $this->addError('consumoIndustrGeral', $e->getMessage());
        }
    }

    public function abrirModalEntregaIndustr(string $produtoId): void
    {
        $this->garantirPermissaoIndustrializacao('criar');
        $this->entregaIndustrProdutoId = $produtoId;
        $this->entregaIndustrQuantidade = null;
        $this->entregaIndustrModalidade = 'retorno_estoque_obra';
        $this->entregaIndustrLocalDestinoId = null;
        $this->entregaIndustrFrenteId = null;
        $this->entregaIndustrTipoRetirante = 'interno';
        $this->entregaIndustrRetiradoPorId = null;
        $this->entregaIndustrRetiradoPorExterno = '';
        $this->entregaIndustrData = now()->toDateString();
        $this->entregaIndustrObservacao = '';
        $this->resetErrorBag(['entregaIndustrGeral']);
        $this->modalEntregaIndustrAberto = true;
    }

    public function fecharModalEntregaIndustr(): void
    {
        $this->modalEntregaIndustrAberto = false;
    }

    public function confirmarEntregaIndustr(): void
    {
        $this->garantirPermissaoIndustrializacao('criar');

        $this->validate([
            'entregaIndustrQuantidade' => 'required|numeric|min:0.001',
            'entregaIndustrLocalDestinoId' => 'required|exists:locais_estoque,id',
            'entregaIndustrData' => 'required|date',
        ]);

        try {
            $produto = ProdutoIndustrializado::where('obra_id', $this->obra->id)->findOrFail($this->entregaIndustrProdutoId);
            $localDestino = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->entregaIndustrLocalDestinoId);
            $modalidade = ModalidadeEntregaProduto::from($this->entregaIndustrModalidade);

            $frente = null;
            $retiradoPor = null;
            $retiradoPorExterno = null;

            if ($modalidade === ModalidadeEntregaProduto::EntregaDiretaCampo) {
                $this->validate(['entregaIndustrFrenteId' => 'required|exists:frentes_trabalho,id']);
                $frente = FrenteTrabalho::where('obra_id', $this->obra->id)->findOrFail($this->entregaIndustrFrenteId);

                if ($this->entregaIndustrTipoRetirante === 'interno') {
                    $this->validate(['entregaIndustrRetiradoPorId' => 'required|exists:users,id']);
                    $retiradoPor = $this->obra->users()->find($this->entregaIndustrRetiradoPorId);
                } else {
                    $this->validate(['entregaIndustrRetiradoPorExterno' => 'required|string|min:1']);
                    $retiradoPorExterno = $this->entregaIndustrRetiradoPorExterno;
                }
            }

            app(RegistrarEntregaProdutoIndustrializado::class)->execute(
                $produto, (float) $this->entregaIndustrQuantidade, $modalidade, $localDestino,
                Carbon::parse($this->entregaIndustrData), Auth::user(),
                frenteCampo: $frente, retiradoPor: $retiradoPor, retiradoPorExterno: $retiradoPorExterno,
                observacao: $this->entregaIndustrObservacao !== '' ? $this->entregaIndustrObservacao : null,
            );

            $this->fecharModalEntregaIndustr();
            unset($this->ordemIndustrDetalhe, $this->produtosDaOrdemDetalheComSaldo);
            $this->dispatch('show-toast', message: 'Entrega registrada.', type: 'success');
        } catch (EntregaProdutoIndustrializadoInvalidaException|SaidaEstoqueInvalidaException $e) {
            $this->addError('entregaIndustrGeral', $e->getMessage());
        }
    }

    // =========================================================
    // Ciclo 20, Etapa 20.7 — Inventário Físico + Divergências + Ajuste
    // =========================================================

    public bool $modalNovoInventarioAberto = false;
    public ?string $invLocalId = null;
    public ?string $invTitulo = null;
    public bool $invContagemCega = false;

    public ?string $inventarioDetalheId = null;

    public ?string $modalContagemItemId = null;
    public ?string $contagemQuantidade = null;
    public ?string $contagemData = null;
    public ?string $contagemObservacao = '';

    public bool $modalItemInesperadoAberto = false;
    public ?string $itemInesperadoMaterialId = null;
    public ?string $itemInesperadoSerial = null;
    public ?string $itemInesperadoQuantidade = null;
    public ?string $itemInesperadoData = null;
    public ?string $itemInesperadoObservacao = '';

    public ?string $modalAjusteItemId = null;
    public ?string $ajusteJustificativa = '';

    public bool $modalCancelarInventarioAberto = false;
    public ?string $cancelarInventarioMotivo = '';

    private function garantirPermissaoInventario(string $acao): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.inventario', $acao), 403);
    }

    /**
     * Ciclo 20, Etapa 20.7 — dupla autorização pra aprovar um Ajuste
     * (decisão do usuário, STOP-and-ask): quem administra o Inventário
     * NÃO tem, por si só, autoridade sobre o ledger físico — precisa
     * também de 'estoque.movimentacao|editar', mesmo padrão já usado em
     * PlanoAcao::transformarEmRestricoes() (Ciclo 11).
     */
    private function garantirPermissaoAprovarAjusteInventario(): void
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.inventario', 'editar'), 403);
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.movimentacao', 'editar'), 403);
    }

    #[Computed]
    public function locaisProprioAtivosParaInventario()
    {
        return LocalEstoque::where('obra_id', $this->obra->id)
            ->where('ativo', true)
            ->where('tipo', '!=', TipoLocalEstoque::Terceiro->value)
            ->orderBy('nome')
            ->get();
    }

    #[Computed]
    public function inventariosEstoque()
    {
        return InventarioEstoque::where('obra_id', $this->obra->id)
            ->with(['localEstoque:id,nome', 'iniciadoPor:id,first_name,last_name', 'concluidoPor:id,first_name,last_name', 'canceladoPor:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function inventariosAbertosCount()
    {
        return InventarioEstoque::where('obra_id', $this->obra->id)
            ->whereIn('status', ['rascunho', 'em_contagem', 'em_analise'])
            ->count();
    }

    #[Computed]
    public function inventarioDetalhe(): ?InventarioEstoque
    {
        if (! $this->inventarioDetalheId) {
            return null;
        }

        return InventarioEstoque::where('obra_id', $this->obra->id)
            ->with('localEstoque')
            ->find($this->inventarioDetalheId);
    }

    #[Computed]
    public function itensInventarioDetalhe()
    {
        $inventario = $this->inventarioDetalhe;
        if (! $inventario) {
            return collect();
        }

        $itens = InventarioItem::where('inventario_estoque_id', $inventario->id)
            ->with(['material:id,codigo,descricao,unidade_medida_id', 'material.unidadeMedida:id,codigo', 'unidadeEstoque'])
            ->orderBy('created_at')
            ->get();

        $divergencias = ConciliacaoInventario::porItens($itens);

        return $itens->map(fn (InventarioItem $item) => [
            'item' => $item,
            'ultima_contagem' => $divergencias[$item->id]['ultima_contagem'] ?? null,
            'diferenca' => $divergencias[$item->id]['diferenca'] ?? null,
            'tem_ajuste' => $divergencias[$item->id]['tem_ajuste'] ?? false,
        ]);
    }

    #[Computed]
    public function materiaisSerializadosParaItemInesperado()
    {
        return Material::where('modo_rastreabilidade', ModoRastreabilidadeMaterial::Serializado->value)
            ->where('ativo', true)
            ->orderBy('codigo')
            ->get();
    }

    public function abrirModalNovoInventario(): void
    {
        $this->garantirPermissaoInventario('criar');
        $this->resetErrorBag();
        $this->invLocalId = null;
        $this->invTitulo = null;
        $this->invContagemCega = false;
        $this->modalNovoInventarioAberto = true;
    }

    public function fecharModalNovoInventario(): void
    {
        $this->modalNovoInventarioAberto = false;
    }

    public function confirmarNovoInventario(): void
    {
        $this->garantirPermissaoInventario('criar');
        $this->resetErrorBag();

        try {
            $local = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($this->invLocalId);

            $inventario = app(CriarInventarioEstoque::class)->execute(
                $local,
                Auth::user(),
                $this->invTitulo !== '' ? $this->invTitulo : null,
                $this->invContagemCega,
            );

            $this->fecharModalNovoInventario();
            unset($this->inventariosEstoque, $this->inventariosAbertosCount);
            $this->inventarioDetalheId = $inventario->id;
            $this->dispatch('show-toast', message: 'Inventário criado em Rascunho.', type: 'success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->addError('novoInventarioGeral', 'Local de Estoque não encontrado.');
        } catch (InventarioEstoqueInvalidoException $e) {
            $this->addError('novoInventarioGeral', $e->getMessage());
        }
    }

    public function abrirInventarioDetalhe(string $id): void
    {
        $this->inventarioDetalheId = $id;
        $this->scanErro = null;
        $this->scanAviso = null;
    }

    public function fecharInventarioDetalhe(): void
    {
        $this->inventarioDetalheId = null;
    }

    public function confirmarIniciarInventario(): void
    {
        $this->garantirPermissaoInventario('criar');
        $this->resetErrorBag();

        try {
            $inventario = InventarioEstoque::where('obra_id', $this->obra->id)->findOrFail($this->inventarioDetalheId);
            app(IniciarInventarioEstoque::class)->execute($inventario, Auth::user());

            unset($this->inventarioDetalhe, $this->itensInventarioDetalhe, $this->inventariosEstoque, $this->inventariosAbertosCount);
            $this->dispatch('show-toast', message: 'Inventário iniciado — snapshot do sistema registrado.', type: 'success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->addError('inventarioDetalheGeral', 'Inventário não encontrado.');
        } catch (InventarioEstoqueInvalidoException $e) {
            $this->addError('inventarioDetalheGeral', $e->getMessage());
        }
    }

    public function abrirModalContagem(string $itemId): void
    {
        $this->garantirPermissaoInventario('criar');
        $this->resetErrorBag();
        $this->modalContagemItemId = $itemId;
        $this->contagemQuantidade = null;
        $this->contagemData = now()->toDateString();
        $this->contagemObservacao = '';
    }

    public function fecharModalContagem(): void
    {
        $this->modalContagemItemId = null;
    }

    public function confirmarContagem(): void
    {
        $this->garantirPermissaoInventario('criar');
        $this->resetErrorBag();

        try {
            $item = InventarioItem::whereHas('inventario', fn ($q) => $q->where('obra_id', $this->obra->id))
                ->findOrFail($this->modalContagemItemId);

            app(RegistrarContagemInventario::class)->execute(
                $item,
                (float) $this->contagemQuantidade,
                Carbon::parse($this->contagemData),
                Auth::user(),
                $this->contagemObservacao !== '' ? $this->contagemObservacao : null,
            );

            $this->fecharModalContagem();
            unset($this->itensInventarioDetalhe);
            $this->dispatch('show-toast', message: 'Contagem registrada.', type: 'success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->addError('contagemGeral', 'Item não encontrado.');
        } catch (InventarioEstoqueInvalidoException $e) {
            $this->addError('contagemGeral', $e->getMessage());
        }
    }

    public function abrirModalItemInesperado(): void
    {
        $this->garantirPermissaoInventario('criar');
        $this->resetErrorBag();
        $this->itemInesperadoMaterialId = null;
        $this->itemInesperadoSerial = '';
        $this->itemInesperadoQuantidade = '1';
        $this->itemInesperadoData = now()->toDateString();
        $this->itemInesperadoObservacao = '';
        $this->modalItemInesperadoAberto = true;
    }

    public function fecharModalItemInesperado(): void
    {
        $this->modalItemInesperadoAberto = false;
    }

    public function confirmarItemInesperado(): void
    {
        $this->garantirPermissaoInventario('criar');
        $this->resetErrorBag();

        try {
            $inventario = InventarioEstoque::where('obra_id', $this->obra->id)->findOrFail($this->inventarioDetalheId);
            $material = Material::findOrFail($this->itemInesperadoMaterialId);

            app(AdicionarItemInesperadoInventario::class)->execute(
                $inventario,
                $material,
                (string) $this->itemInesperadoSerial,
                (float) $this->itemInesperadoQuantidade,
                Carbon::parse($this->itemInesperadoData),
                Auth::user(),
                $this->itemInesperadoObservacao !== '' ? $this->itemInesperadoObservacao : null,
            );

            $this->fecharModalItemInesperado();
            unset($this->itensInventarioDetalhe);
            $this->dispatch('show-toast', message: 'Item inesperado registrado.', type: 'success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->addError('itemInesperadoGeral', 'Inventário ou Material não encontrado.');
        } catch (InventarioEstoqueInvalidoException $e) {
            $this->addError('itemInesperadoGeral', $e->getMessage());
        }
    }

    public function confirmarMoverParaAnalise(): void
    {
        $this->garantirPermissaoInventario('editar');
        $this->resetErrorBag();

        try {
            $inventario = InventarioEstoque::where('obra_id', $this->obra->id)->findOrFail($this->inventarioDetalheId);
            app(MoverInventarioParaAnalise::class)->execute($inventario, Auth::user());

            unset($this->inventarioDetalhe, $this->inventariosEstoque);
            $this->dispatch('show-toast', message: 'Inventário movido para Análise.', type: 'success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->addError('inventarioDetalheGeral', 'Inventário não encontrado.');
        } catch (InventarioEstoqueInvalidoException $e) {
            $this->addError('inventarioDetalheGeral', $e->getMessage());
        }
    }

    public function abrirModalAjuste(string $itemId): void
    {
        $this->garantirPermissaoAprovarAjusteInventario();
        $this->resetErrorBag();
        $this->modalAjusteItemId = $itemId;
        $this->ajusteJustificativa = '';
    }

    public function fecharModalAjuste(): void
    {
        $this->modalAjusteItemId = null;
    }

    public function confirmarAjuste(): void
    {
        $this->garantirPermissaoAprovarAjusteInventario();
        $this->resetErrorBag();

        try {
            $item = InventarioItem::whereHas('inventario', fn ($q) => $q->where('obra_id', $this->obra->id))
                ->findOrFail($this->modalAjusteItemId);

            app(AprovarAjusteInventario::class)->execute($item, (string) $this->ajusteJustificativa, Auth::user());

            $this->fecharModalAjuste();
            unset($this->itensInventarioDetalhe);
            $this->dispatch('show-toast', message: 'Ajuste aprovado — movimentação registrada.', type: 'success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->addError('ajusteGeral', 'Item não encontrado.');
        } catch (AjusteInventarioInvalidoException|SaldoFisicoInsuficienteException $e) {
            $this->addError('ajusteGeral', $e->getMessage());
        }
    }

    public function confirmarConcluirInventario(): void
    {
        $this->garantirPermissaoInventario('editar');
        $this->resetErrorBag();

        try {
            $inventario = InventarioEstoque::where('obra_id', $this->obra->id)->findOrFail($this->inventarioDetalheId);
            app(ConcluirInventarioEstoque::class)->execute($inventario, Auth::user());

            unset($this->inventarioDetalhe, $this->inventariosEstoque, $this->inventariosAbertosCount);
            $this->dispatch('show-toast', message: 'Inventário concluído.', type: 'success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->addError('inventarioDetalheGeral', 'Inventário não encontrado.');
        } catch (InventarioEstoqueInvalidoException $e) {
            $this->addError('inventarioDetalheGeral', $e->getMessage());
        }
    }

    public function abrirModalCancelarInventario(): void
    {
        $this->garantirPermissaoInventario('excluir');
        $this->resetErrorBag();
        $this->cancelarInventarioMotivo = '';
        $this->modalCancelarInventarioAberto = true;
    }

    public function fecharModalCancelarInventario(): void
    {
        $this->modalCancelarInventarioAberto = false;
    }

    public function confirmarCancelarInventario(): void
    {
        $this->garantirPermissaoInventario('excluir');
        $this->resetErrorBag();

        try {
            $inventario = InventarioEstoque::where('obra_id', $this->obra->id)->findOrFail($this->inventarioDetalheId);
            app(CancelarInventarioEstoque::class)->execute($inventario, (string) $this->cancelarInventarioMotivo, Auth::user());

            $this->fecharModalCancelarInventario();
            unset($this->inventarioDetalhe, $this->inventariosEstoque, $this->inventariosAbertosCount);
            $this->dispatch('show-toast', message: 'Inventário cancelado.', type: 'success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->addError('cancelarInventarioGeral', 'Inventário não encontrado.');
        } catch (InventarioEstoqueInvalidoException $e) {
            $this->addError('cancelarInventarioGeral', $e->getMessage());
        }
    }

    // =========================================================
    // Ciclo 20, Etapa 20.8 — Identificação por QR/Código de Barras
    // =========================================================

    public ?string $scanErro = null;
    public ?string $scanAviso = null;

    /**
     * Método CENTRAL de resolução de scan — reaproveitado por TODOS os
     * modais (Entrada/Saída/Transferência/Reserva), nunca duplicado por
     * tela (Seção 14/28 do pedido). Só PREENCHE o campo já existente do
     * modal já aberto — nunca dispara nenhuma Action sozinho (Seção 23:
     * "escanear nunca gera evento físico automaticamente" — a confirmação
     * final continua sendo sempre o botão "Confirmar" de cada modal, já
     * existente).
     *
     * `$tipoEsperado` (opcional): quando informado, rejeita um código que
     * resolve pra um tipo diferente (ex.: escanear um Material onde se
     * esperava um Local) com mensagem clara, em vez de setar o campo
     * errado silenciosamente.
     */
    public function resolverEAplicarScan(string $codigo, string $campoAlvo, ?string $tipoEsperado = null): void
    {
        $this->scanErro = null;
        $this->scanAviso = null;

        try {
            $resultado = \App\Support\Estoque\ResolverCodigoEstoque::resolver($codigo, $this->obra->id);
        } catch (\App\Exceptions\CodigoEstoqueInvalidoException $e) {
            $this->scanErro = $e->getMessage();

            return;
        }

        if ($tipoEsperado !== null && $resultado->tipo !== $tipoEsperado) {
            $this->scanErro = "Este código identifica um(a) {$resultado->tipo} — esperado um(a) {$tipoEsperado}. Verifique se escaneou o item certo.";

            return;
        }

        $this->{$campoAlvo} = $resultado->entidade->id;

        // Livewire não dispara updated{Campo}() automaticamente quando a
        // propriedade é setada via PHP direto (só quando vem de wire:model
        // do front-end) — chamamos manualmente pra invalidar os mesmos
        // computeds que já seriam invalidados se o operador tivesse usado
        // o <select> manual, sem duplicar nenhuma lógica de cache.
        $metodoUpdated = 'updated' . ucfirst($campoAlvo);
        if (method_exists($this, $metodoUpdated)) {
            $this->$metodoUpdated();
        }

        if ($resultado->ativo === false) {
            $this->scanAviso = 'Atenção: esta entidade está INATIVA no cadastro — a operação pode ser bloqueada pela regra de negócio.';
        }
    }

    /**
     * Scan dedicado do fluxo de Inventário (Seção 20) — diferente dos
     * demais porque não preenche um campo solto: localiza o
     * `InventarioItem` já esperado (do snapshot) pro Material/Unidade
     * escaneado e abre o modal de contagem dele. Serial inesperado NUNCA
     * é resolvido automaticamente aqui — segue a mesma decisão da 20.7,
     * o operador usa "Registrar serial inesperado" manualmente.
     */
    /**
     * Ciclo 20, Etapa 20.8 — variante do scan pro par "Material/Lote-
     * Bobina-Serial" já existente em Saída/Transferência/Reserva: o
     * MESMO campo de scan aceita tanto um código de Material (materiais
     * Quantitativos) quanto de Unidade (Lote/Bobina/Serial) — quando é
     * Unidade, preenche o Material dela automaticamente também (Seção
     * 6/7 do pedido: "scan Material/Unidade" é tratado como um único
     * passo do fluxo).
     */
    public function resolverEAplicarScanMaterialOuUnidade(string $codigo, string $campoMaterial, ?string $campoUnidade = null): void
    {
        $this->scanErro = null;
        $this->scanAviso = null;

        try {
            $resultado = \App\Support\Estoque\ResolverCodigoEstoque::resolver($codigo, $this->obra->id);
        } catch (\App\Exceptions\CodigoEstoqueInvalidoException $e) {
            $this->scanErro = $e->getMessage();

            return;
        }

        if (! in_array($resultado->tipo, ['material', 'unidade'], true)) {
            $this->scanErro = "Este código identifica um(a) {$resultado->tipo} — esperado um Material ou Lote/Bobina/Serial.";

            return;
        }

        $materialId = $resultado->tipo === 'material' ? $resultado->entidade->id : $resultado->entidade->material_id;
        $unidadeId = $resultado->tipo === 'unidade' ? $resultado->entidade->id : null;

        // ORDEM IMPORTA: setar o Material e disparar seu hook PRIMEIRO —
        // updated{Material}() já reseta {Unidade} pra null como efeito
        // colateral esperado (mesmo comportamento de uma troca manual no
        // <select>, ver updatedTransferenciaMaterialId()/updatedSaidaMaterialId()).
        // Só DEPOIS setamos a Unidade de verdade, sobrescrevendo esse
        // reset — inverter a ordem apaga silenciosamente a Unidade
        // resolvida pelo scan (achado real desta etapa, coberto por
        // teste de regressão).
        $this->{$campoMaterial} = $materialId;
        $metodoMaterial = 'updated' . ucfirst($campoMaterial);
        if (method_exists($this, $metodoMaterial)) {
            $this->$metodoMaterial();
        }

        if ($campoUnidade) {
            $this->{$campoUnidade} = $unidadeId;
            $metodoUnidade = 'updated' . ucfirst($campoUnidade);
            if (method_exists($this, $metodoUnidade)) {
                $this->$metodoUnidade();
            }
        }

        if ($resultado->tipo === 'material' && $resultado->ativo === false) {
            $this->scanAviso = 'Atenção: este Material está INATIVO no cadastro — a operação pode ser bloqueada pela regra de negócio.';
        }
    }

    public function processarScanInventario(string $codigo): void
    {
        $this->scanErro = null;
        $this->scanAviso = null;

        $inventario = $this->inventarioDetalhe;
        if (! $inventario) {
            return;
        }

        try {
            $resultado = \App\Support\Estoque\ResolverCodigoEstoque::resolver($codigo, $this->obra->id);
        } catch (\App\Exceptions\CodigoEstoqueInvalidoException $e) {
            $this->scanErro = $e->getMessage();

            return;
        }

        if ($resultado->tipo === 'local') {
            $this->scanErro = "Este é um código de Local — o Inventário já está fixado no Local \"{$inventario->localEstoque?->nome}\".";

            return;
        }

        $materialId = $resultado->tipo === 'material' ? $resultado->entidade->id : $resultado->entidade->material_id;
        $unidadeId = $resultado->tipo === 'unidade' ? $resultado->entidade->id : null;

        $item = \App\Models\InventarioItem::where('inventario_estoque_id', $inventario->id)
            ->where('material_id', $materialId)
            ->where('unidade_estoque_id', $unidadeId)
            ->first();

        if (! $item) {
            $this->scanErro = 'Este item não faz parte do snapshot deste Inventário — se for um serial físico inesperado, use "Registrar serial inesperado".';

            return;
        }

        $this->abrirModalContagem($item->id);
    }

    /**
     * Ciclo 20, Etapa 20.8 — "Imprimir etiqueta" (Seção 10/29). Sempre
     * `estoque.movimentacao|ver` (mesmo slug que já cobre visualizar
     * Material/Local/Unidade — nenhum slug novo, Seção 27). Nunca imprime
     * saldo/quantidade/Local atual (Seção 10/1) — só identidade.
     */
    public function exportarEtiquetaMaterial(string $materialId, string $tamanho = 'pequena')
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.movimentacao', 'ver'), 403);
        $material = Material::findOrFail($materialId);
        $etiquetas = \App\Support\Estoque\MontarDadosEtiquetaEstoque::paraEntidades(collect([$material]));

        return response()->streamDownload(function () use ($etiquetas, $tamanho) {
            echo \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.etiqueta-estoque-pdf', ['etiquetas' => $etiquetas, 'tamanho' => $tamanho])->output();
        }, "etiqueta-material-{$material->codigo}.pdf");
    }

    public function exportarEtiquetaLocal(string $localId, string $tamanho = 'pequena')
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.movimentacao', 'ver'), 403);
        $local = LocalEstoque::where('obra_id', $this->obra->id)->findOrFail($localId);
        $etiquetas = \App\Support\Estoque\MontarDadosEtiquetaEstoque::paraEntidades(collect([$local]));

        return response()->streamDownload(function () use ($etiquetas, $tamanho) {
            echo \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.etiqueta-estoque-pdf', ['etiquetas' => $etiquetas, 'tamanho' => $tamanho])->output();
        }, "etiqueta-local-{$local->nome}.pdf");
    }

    public function exportarEtiquetaUnidade(string $unidadeId, string $tamanho = 'pequena')
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.movimentacao', 'ver'), 403);
        $unidade = \App\Models\UnidadeEstoque::findOrFail($unidadeId);
        $etiquetas = \App\Support\Estoque\MontarDadosEtiquetaEstoque::paraEntidades(collect([$unidade]));

        return response()->streamDownload(function () use ($etiquetas, $tamanho) {
            echo \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.etiqueta-estoque-pdf', ['etiquetas' => $etiquetas, 'tamanho' => $tamanho])->output();
        }, 'etiqueta-unidade.pdf');
    }

    /**
     * Impressão em lote (Seção 29) — todos os Materiais ativos da obra
     * (catálogo do tenant, mas só os já usados/visíveis nesta tela) numa
     * única listagem A4.
     */
    public function exportarEtiquetasMateriaisLote()
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.movimentacao', 'ver'), 403);
        $materiais = Material::where('ativo', true)->orderBy('codigo')->get();
        $etiquetas = \App\Support\Estoque\MontarDadosEtiquetaEstoque::paraEntidades($materiais);

        return response()->streamDownload(function () use ($etiquetas) {
            echo \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.etiqueta-estoque-pdf', ['etiquetas' => $etiquetas, 'tamanho' => 'a4'])->output();
        }, 'etiquetas-materiais.pdf');
    }

    public function exportarEtiquetasLocaisLote()
    {
        abort_unless(Auth::user()->temPermissaoNaObra($this->obra->id, 'estoque.movimentacao', 'ver'), 403);
        $locais = LocalEstoque::where('obra_id', $this->obra->id)->where('ativo', true)->orderBy('nome')->get();
        $etiquetas = \App\Support\Estoque\MontarDadosEtiquetaEstoque::paraEntidades($locais);

        return response()->streamDownload(function () use ($etiquetas) {
            echo \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.etiqueta-estoque-pdf', ['etiquetas' => $etiquetas, 'tamanho' => 'a4'])->output();
        }, 'etiquetas-locais.pdf');
    }
}
?>
<div>
  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'materiais') active @endif" wire:click="$set('abaAtiva', 'materiais')">Materiais</button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'locais') active @endif" wire:click="$set('abaAtiva', 'locais')">Locais de Estoque</button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'recebimentos') active @endif" wire:click="$set('abaAtiva', 'recebimentos')">
        Recebimentos Pendentes
        @if ($this->recebimentosPendentes->count() > 0)
          <span class="badge bg-label-warning ms-1">{{ $this->recebimentosPendentes->count() }}</span>
        @endif
      </button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'saidas') active @endif" wire:click="$set('abaAtiva', 'saidas')">Saída / Retirada</button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'transferencias') active @endif" wire:click="$set('abaAtiva', 'transferencias')">Transferir</button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'movimentacoes') active @endif" wire:click="$set('abaAtiva', 'movimentacoes')">Movimentações</button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'planejamento') active @endif" wire:click="$set('abaAtiva', 'planejamento')">Planejamento / Reservas</button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'conciliacao') active @endif" wire:click="$set('abaAtiva', 'conciliacao')">
        Conciliação / Aplicação
        @if ($this->saidasComPendencia->count() > 0)
          <span class="badge bg-label-warning ms-1">{{ $this->saidasComPendencia->count() }}</span>
        @endif
      </button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'industrializacao') active @endif" wire:click="$set('abaAtiva', 'industrializacao')">Industrialização em Terceiros</button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link @if ($abaAtiva === 'inventario') active @endif" wire:click="$set('abaAtiva', 'inventario')">
        Inventário
        @if ($this->inventariosAbertosCount > 0)
          <span class="badge bg-label-info ms-1">{{ $this->inventariosAbertosCount }}</span>
        @endif
      </button>
    </li>
  </ul>

  {{-- ===================== MATERIAIS ===================== --}}
  @if ($abaAtiva === 'materiais')
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Catálogo de Materiais</h5>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="exportarEtiquetasMateriaisLote" title="Imprimir etiquetas de todos os Materiais ativos (Ciclo 20, Etapa 20.8)">
            <i class="bx bx-qr"></i> Etiquetas (lote)
          </button>
          <button type="button" class="btn btn-primary btn-sm" wire:click="abrirModalMaterial">
            <i class="bx bx-plus"></i> Novo Material
          </button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Código</th>
              <th>Descrição</th>
              <th>Unidade</th>
              <th>Família</th>
              <th>Rastreabilidade</th>
              <th>Saldo</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($this->materiais as $linha)
              <tr wire:key="material-{{ $linha['material']->id }}">
                <td>{{ $linha['material']->codigo }}</td>
                <td>{{ $linha['material']->descricao }}</td>
                <td>{{ $linha['material']->unidadeMedida?->codigo }}</td>
                <td>{{ $linha['material']->familiaMaterial?->nome ?? '—' }}</td>
                <td>{{ $linha['material']->modo_rastreabilidade->label() }}</td>
                <td>{{ number_format($linha['saldo'], 3, ',', '.') }}</td>
                <td>
                  @if ($linha['material']->ativo)
                    <span class="badge bg-label-success">Ativo</span>
                  @else
                    <span class="badge bg-label-secondary">Inativo</span>
                  @endif
                </td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-icon" wire:click="exportarEtiquetaMaterial('{{ $linha['material']->id }}')" title="Imprimir etiqueta">
                    <i class="bx bx-qr"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-icon" wire:click="abrirModalMaterial('{{ $linha['material']->id }}')" title="Editar">
                    <i class="bx bx-edit"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-icon" wire:click="alternarStatusMaterial('{{ $linha['material']->id }}')" title="{{ $linha['material']->ativo ? 'Inativar' : 'Reativar' }}">
                    <i class="bx {{ $linha['material']->ativo ? 'bx-block' : 'bx-check-circle' }}"></i>
                  </button>
                </td>
              </tr>
            @empty
              <tr><td colspan="8" class="text-center text-muted py-4">Nenhum Material cadastrado ainda.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    @if ($modalMaterialAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">{{ $editandoMaterialId ? 'Editar Material' : 'Novo Material' }}</h5>
              <button type="button" class="btn-close" wire:click="fecharModalMaterial"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label">Código</label>
                <input type="text" class="form-control" wire:model="materialCodigo">
                @error('materialCodigo') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Descrição</label>
                <input type="text" class="form-control" wire:model="materialDescricao">
                @error('materialDescricao') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Unidade de Medida</label>
                <select class="form-select" wire:model="materialUnidadeMedidaId">
                  <option value="">Selecione...</option>
                  @foreach ($this->unidadesMedida as $u)
                    <option value="{{ $u->id }}">{{ $u->codigo }} — {{ $u->nome }}</option>
                  @endforeach
                </select>
                @error('materialUnidadeMedidaId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Família (opcional)</label>
                <select class="form-select" wire:model="materialFamiliaId">
                  <option value="">—</option>
                  @foreach ($this->familiasMaterial as $f)
                    <option value="{{ $f->id }}">{{ $f->nome }}</option>
                  @endforeach
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Modo de Rastreabilidade</label>
                <select class="form-select" wire:model="materialModoRastreabilidade" @if ($editandoMaterialId) disabled @endif>
                  @foreach (\App\Enums\ModoRastreabilidadeMaterial::cases() as $modo)
                    <option value="{{ $modo->value }}">{{ $modo->label() }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalMaterial">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="salvarMaterial">Salvar</button>
            </div>
          </div>
        </div>
      </div>
    @endif
  @endif

  {{-- ===================== LOCAIS ===================== --}}
  @if ($abaAtiva === 'locais')
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Locais de Estoque</h5>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="exportarEtiquetasLocaisLote" title="Imprimir etiquetas de todos os Locais ativos (Ciclo 20, Etapa 20.8)">
            <i class="bx bx-qr"></i> Etiquetas (lote)
          </button>
          <button type="button" class="btn btn-primary btn-sm" wire:click="abrirModalLocal">
            <i class="bx bx-plus"></i> Novo Local
          </button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Tipo</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($this->locais as $local)
              <tr wire:key="local-{{ $local->id }}">
                <td>{{ $local->nome }}</td>
                <td>{{ $local->tipo->label() }}</td>
                <td>
                  @if ($local->ativo)
                    <span class="badge bg-label-success">Ativo</span>
                  @else
                    <span class="badge bg-label-secondary">Inativo</span>
                  @endif
                </td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-icon" wire:click="exportarEtiquetaLocal('{{ $local->id }}')" title="Imprimir etiqueta">
                    <i class="bx bx-qr"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-icon" wire:click="abrirModalLocal('{{ $local->id }}')" title="Editar">
                    <i class="bx bx-edit"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-icon" wire:click="alternarStatusLocal('{{ $local->id }}')" title="{{ $local->ativo ? 'Inativar' : 'Reativar' }}">
                    <i class="bx {{ $local->ativo ? 'bx-block' : 'bx-check-circle' }}"></i>
                  </button>
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-muted py-4">Nenhum Local de Estoque cadastrado ainda.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    @if ($modalLocalAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">{{ $editandoLocalId ? 'Editar Local' : 'Novo Local de Estoque' }}</h5>
              <button type="button" class="btn-close" wire:click="fecharModalLocal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label">Nome</label>
                <input type="text" class="form-control" wire:model="localNome">
                @error('localNome') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Tipo</label>
                <select class="form-select" wire:model="localTipo">
                  @foreach (\App\Enums\TipoLocalEstoque::cases() as $tipo)
                    <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalLocal">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="salvarLocal">Salvar</button>
            </div>
          </div>
        </div>
      </div>
    @endif
  @endif

  {{-- ===================== RECEBIMENTOS PENDENTES ===================== --}}
  @if ($abaAtiva === 'recebimentos')
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Recebimentos Pendentes de Incorporação ao Estoque</h5>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Pedido</th>
              <th>Item</th>
              <th>Recebido</th>
              <th>Pendente</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($this->recebimentosPendentes as $linha)
              <tr wire:key="pendente-{{ $linha['recebimento']->id }}">
                <td>{{ $linha['pedido_numero'] ?? '—' }}</td>
                <td>{{ $linha['descricao'] ?? '—' }}</td>
                <td>{{ number_format($linha['recebido'], 3, ',', '.') }}</td>
                <td>{{ number_format($linha['pendente'], 3, ',', '.') }}</td>
                <td class="text-end">
                  @if ($linha['tem_material'])
                    <span class="badge bg-label-info me-1">{{ $linha['material_codigo'] }}</span>
                    @if ($linha['pode_trocar'])
                      <button type="button" class="btn btn-link btn-sm p-0 me-2" wire:click="abrirModalAssociarMaterial('{{ $linha['recebimento']->id }}')">Trocar</button>
                    @else
                      <i class="bx bx-lock-alt text-muted me-2" title="Associação congelada — este item já possui Pedido de Compra emitido ou entrada em estoque."></i>
                    @endif
                    <button type="button" class="btn btn-sm btn-primary" wire:click="abrirModalEntrada('{{ $linha['recebimento']->id }}')">
                      Dar entrada no estoque
                    </button>
                  @else
                    <button type="button" class="btn btn-sm btn-outline-warning" wire:click="abrirModalAssociarMaterial('{{ $linha['recebimento']->id }}')">
                      Associar Material
                    </button>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-muted py-4">Nenhum recebimento pendente de incorporação.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    @if ($modalEntradaAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Dar entrada no estoque</h5>
              <button type="button" class="btn-close" wire:click="fecharModalEntrada"></button>
            </div>
            <div class="modal-body">
              @error('entradaGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              @if ($scanErro) <div class="alert alert-warning">{{ $scanErro }}</div> @endif

              @include('pages::radar._partials.escanear-codigo', ['campoAlvo' => 'entradaLocalId', 'tipoEsperado' => 'local', 'label' => 'Local'])

              <div class="mb-3">
                <label class="form-label">Local de Estoque</label>
                <select class="form-select" wire:model="entradaLocalId">
                  <option value="">Selecione...</option>
                  @foreach ($this->locais->where('ativo', true) as $local)
                    <option value="{{ $local->id }}">{{ $local->nome }}</option>
                  @endforeach
                </select>
                @error('entradaLocalId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>

              <div class="mb-3">
                <label class="form-label">Quantidade</label>
                <input type="number" step="0.001" class="form-control" wire:model="entradaQuantidade">
                @error('entradaQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>

              <div class="mb-3">
                <label class="form-label">Data da entrada</label>
                <input type="date" class="form-control" wire:model="entradaData" max="{{ now()->toDateString() }}">
                @error('entradaData') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>

              @if ($this->modoRastreabilidadeDoRecebimentoEmEdicao === 'lote')
                <div class="mb-3">
                  <label class="form-label">Lote / Bobina</label>
                  <input type="text" class="form-control" wire:model="entradaCodigoLote" placeholder="Ex.: B-001">
                </div>
              @endif

              @if ($this->modoRastreabilidadeDoRecebimentoEmEdicao === 'serializado')
                <div class="mb-3">
                  <label class="form-label">Serial</label>
                  <input type="text" class="form-control" wire:model="entradaSerialUnico" placeholder="Ex.: SN-00123">
                </div>
              @endif

              @if (in_array($this->modoRastreabilidadeDoRecebimentoEmEdicao, ['lote', 'serializado'], true))
                <div class="mb-3">
                  <label class="form-label">Identificador logístico (opcional)</label>
                  <input type="text" class="form-control" wire:model="entradaIdentificadorLogistico">
                </div>
              @endif

              <div class="mb-3">
                <label class="form-label">Observação (opcional)</label>
                <textarea class="form-control" wire:model="entradaObservacao" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalEntrada">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarEntrada">Confirmar Entrada</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    @if ($modalAssociarAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Associar Material / SKU</h5>
              <button type="button" class="btn-close" wire:click="fecharModalAssociarMaterial"></button>
            </div>
            <div class="modal-body">
              @error('associarGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror

              <div class="mb-3">
                <label class="form-label">Pesquisar Material (código ou descrição)</label>
                <input type="text" class="form-control" wire:model.live.debounce.300ms="buscaMaterialAssociar" placeholder="Digite para pesquisar...">
              </div>

              <div class="mb-3">
                <label class="form-label">Material</label>
                <select class="form-select" wire:model="materialSelecionadoId" size="8">
                  @forelse ($this->materiaisAtivosParaAssociar as $m)
                    <option value="{{ $m->id }}">{{ $m->codigo }} — {{ $m->descricao }}</option>
                  @empty
                    <option value="" disabled>Nenhum Material ativo encontrado.</option>
                  @endforelse
                </select>
                @error('materialSelecionadoId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalAssociarMaterial">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarAssociarMaterial">Associar</button>
            </div>
          </div>
        </div>
      </div>
    @endif
  @endif

  {{-- ===================== MOVIMENTAÇÕES ===================== --}}
  {{-- ===================== SAÍDA / RETIRADA (Ciclo 20, Etapa 20.3) ===================== --}}
  @if ($abaAtiva === 'saidas')
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0">Saída / Retirada de Estoque</h5>
          <small class="text-muted">Registra o fato físico da retirada — nunca a aplicação final conciliada na Frente (isso é uma etapa futura).</small>
        </div>
        <button type="button" class="btn btn-primary btn-sm" wire:click="abrirModalSaida">
          <i class="bx bx-log-out-circle"></i> Registrar Saída
        </button>
      </div>
      <div class="card-body">
        <p class="text-muted mb-0">Use "Registrar Saída" para dar baixa física de um Material — a partir de uma Reserva Ativa (consumindo-a parcial ou totalmente) ou como retirada livre/emergencial sem Reserva vinculada.</p>
      </div>
    </div>

    @if ($modalSaidaAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Registrar Saída de Estoque</h5>
              <button type="button" class="btn-close" wire:click="fecharModalSaida"></button>
            </div>
            <div class="modal-body">
              @error('saidaGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              @if ($scanErro) <div class="alert alert-warning">{{ $scanErro }}</div> @endif
              @if ($scanAviso) <div class="alert alert-warning">{{ $scanAviso }}</div> @endif

              <div class="row g-2 mb-2">
                <div class="col-md-6">
                  @include('pages::radar._partials.escanear-codigo', ['campoAlvo' => 'saidaLocalId', 'tipoEsperado' => 'local', 'label' => 'Local'])
                </div>
                <div class="col-md-6">
                  @include('pages::radar._partials.escanear-codigo', ['campoAlvo' => 'saidaMaterialId', 'campoUnidadeAlvo' => 'saidaUnidadeId', 'label' => 'Material/Lote/Serial'])
                </div>
              </div>

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Material</label>
                  <select class="form-select" wire:model.live="saidaMaterialId" @if ($saidaReservaId) disabled @endif>
                    <option value="">Selecione...</option>
                    @foreach ($this->materiaisAtivosParaSaida as $m)
                      <option value="{{ $m->id }}">{{ $m->codigo }} — {{ $m->descricao }}</option>
                    @endforeach
                  </select>
                  @error('saidaMaterialId') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                  <label class="form-label">Local de Estoque</label>
                  <select class="form-select" wire:model.live="saidaLocalId" @if ($saidaReservaId) disabled @endif>
                    <option value="">Selecione...</option>
                    @foreach ($this->locaisAtivosParaSaida as $l)
                      <option value="{{ $l->id }}">{{ $l->nome }}</option>
                    @endforeach
                  </select>
                  @error('saidaLocalId') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                @if ($this->unidadesDisponiveisParaSaida->isNotEmpty() || $saidaUnidadeId)
                  <div class="col-md-6">
                    <label class="form-label">Lote/Bobina/Serial</label>
                    <select class="form-select" wire:model.live="saidaUnidadeId" @if ($saidaReservaId) disabled @endif>
                      <option value="">Selecione...</option>
                      @foreach ($this->unidadesDisponiveisParaSaida as $x)
                        <option value="{{ $x['unidade']->id }}">
                          {{ $x['unidade']->codigo_lote ?? $x['unidade']->serial_unico }} — disponível {{ number_format($x['disponivel'], 3, ',', '.') }}
                        </option>
                      @endforeach
                    </select>
                  </div>
                @endif

                <div class="col-md-6">
                  <label class="form-label">Reserva (opcional)</label>
                  <select class="form-select" wire:model.live="saidaReservaId">
                    <option value="">Sem Reserva (retirada livre/emergencial)</option>
                    @foreach ($this->reservasAtivasParaSaida as $x)
                      <option value="{{ $x['reserva']->id }}">
                        {{ $x['reserva']->pacote?->codigo ?? $x['reserva']->pacote?->nome }} —
                        {{ $x['reserva']->destinacaoPlanejada?->frenteTrabalho?->nome ?? 'sem Frente' }} —
                        pendente {{ number_format($x['saldo_pendente'], 3, ',', '.') }} ({{ $x['reserva']->localEstoque?->nome }})
                      </option>
                    @endforeach
                  </select>
                  <small class="text-muted">Selecione um Material para ver as Reservas Ativas disponíveis.</small>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Pacote de Compra (opcional)</label>
                  <select class="form-select" wire:model="saidaPacoteId" @if ($saidaReservaId) disabled @endif>
                    <option value="">Demanda pendente de conciliação</option>
                    @foreach ($this->pacotesParaSaida as $p)
                      <option value="{{ $p->id }}">{{ $p->codigo ?? $p->nome }}</option>
                    @endforeach
                  </select>
                  @if (! $saidaReservaId && ! $saidaPacoteId)
                    <small class="text-muted">Demanda/Pacote pendente de conciliação — não bloqueia a saída.</small>
                  @endif
                </div>

                <div class="col-md-6">
                  <label class="form-label">Frente informada na retirada (opcional)</label>
                  <select class="form-select" wire:model="saidaFrenteId">
                    <option value="">Pendente de conciliação</option>
                    @foreach ($this->frentesTrabalho as $f)
                      <option value="{{ $f->id }}">{{ $f->nome }}</option>
                    @endforeach
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Quantidade</label>
                  <input type="number" step="0.001" class="form-control" wire:model.live="saidaQuantidade">
                  @error('saidaQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
                  @if (! is_null($this->saldoFisicoPreviewSaida))
                    <small class="text-muted">Saldo físico: {{ number_format($this->saldoFisicoPreviewSaida, 3, ',', '.') }}</small>
                  @endif
                  @if (! is_null($this->saldoNaoReservadoPreviewSaida))
                    <br><small class="{{ $this->saidaExcedeSaldoNaoReservado ? 'text-danger' : 'text-muted' }}">Saldo não reservado por ninguém: {{ number_format($this->saldoNaoReservadoPreviewSaida, 3, ',', '.') }}</small>
                  @endif
                </div>

                <div class="col-md-6">
                  <label class="form-label">Data da saída</label>
                  <input type="date" class="form-control" wire:model="saidaData">
                  @error('saidaData') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                  <label class="form-label">Quem retirou o material?</label>
                  <div class="d-flex gap-3 mb-2">
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="saidaTipoRetirante" id="saidaTipoRetiranteInterno" value="interno" wire:model.live="saidaTipoRetirante">
                      <label class="form-check-label" for="saidaTipoRetiranteInterno">Usuário do sistema</label>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="saidaTipoRetirante" id="saidaTipoRetiranteExterno" value="externo" wire:model.live="saidaTipoRetirante">
                      <label class="form-check-label" for="saidaTipoRetiranteExterno">Pessoa externa (sem login)</label>
                    </div>
                  </div>

                  @if ($saidaTipoRetirante === 'interno')
                    <select class="form-select" wire:model="saidaRetiradoPorId">
                      <option value="">Selecione...</option>
                      @foreach ($this->usuariosDaObraParaSaida as $u)
                        <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                      @endforeach
                    </select>
                    @error('saidaRetiradoPorId') <div class="text-danger small">{{ $message }}</div> @enderror
                  @else
                    <input type="text" class="form-control" wire:model="saidaRetiradoPorExterno" placeholder="Ex.: Carlos Silva — Eletricista — Empresa XYZ">
                    @error('saidaRetiradoPorExterno') <div class="text-danger small">{{ $message }}</div> @enderror
                  @endif
                </div>

                <div class="col-12">
                  <label class="form-label">Observação (opcional)</label>
                  <textarea class="form-control" wire:model="saidaObservacao" rows="2"></textarea>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalSaida">Cancelar</button>
              @if ($this->saidaExcedeSaldoNaoReservado)
                <button type="button" class="btn btn-warning" onclick="confirmarAcao(this, { mensagem: 'Esta quantidade excede o saldo não reservado — parte do estoque reservado para outra demanda será consumida. A Reserva original NÃO será alterada. Confirma mesmo assim?', metodo: 'confirmarSaida', args: [], corBotao: 'warning', icone: 'bx-error' })">
                  <i class="bx bx-error"></i> Confirmar mesmo assim
                </button>
              @else
                <button type="button" class="btn btn-primary" wire:click="confirmarSaida">Registrar Saída</button>
              @endif
            </div>
          </div>
        </div>
      </div>
    @endif
  @endif

  @if ($abaAtiva === 'transferencias')
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0">Transferência entre Locais de Estoque</h5>
          <small class="text-muted">Move material entre dois Locais PRÓPRIOS da obra (mudança de localização/custódia) — nunca é consumo/aplicação. Transferência envolvendo um Local de Terceiro é feita via Ordem de Industrialização.</small>
        </div>
        <button type="button" class="btn btn-primary btn-sm" wire:click="abrirModalTransferencia">
          <i class="bx bx-transfer"></i> Transferir
        </button>
      </div>
      <div class="card-body">
        <p class="text-muted mb-0">Selecione o Material, o Local de origem e o Local de destino — o saldo físico da origem é sempre validado antes de confirmar.</p>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">
        <h5 class="mb-0">Histórico de Transferências</h5>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>Data</th>
              <th>Material</th>
              <th>Lote/Serial</th>
              <th>Origem</th>
              <th>Destino</th>
              <th>Quantidade</th>
              <th>Responsável</th>
              <th>Observação</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($this->transferenciasEstoque as $t)
              <tr wire:key="transferencia-{{ $t->id }}">
                <td>{{ $t->ocorrido_em->format('d/m/Y') }}</td>
                <td>{{ $t->material->codigo }} — {{ $t->material->descricao }}</td>
                <td>{{ $t->unidadeEstoque?->codigo_lote ?? $t->unidadeEstoque?->serial_unico ?? '—' }}</td>
                <td>{{ $t->localOrigem?->nome }}</td>
                <td>{{ $t->localDestino?->nome }}</td>
                <td>{{ number_format($t->quantidade, 3, ',', '.') }}</td>
                <td>{{ $t->registradoPor ? $t->registradoPor->first_name . ' ' . $t->registradoPor->last_name : 'Usuário removido' }}</td>
                <td>{{ $t->observacao ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma transferência registrada.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    @if ($modalTransferenciaAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Transferir entre Locais de Estoque</h5>
              <button type="button" class="btn-close" wire:click="fecharModalTransferencia"></button>
            </div>
            <div class="modal-body">
              @error('transferenciaGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              @if ($scanErro) <div class="alert alert-warning">{{ $scanErro }}</div> @endif
              @if ($scanAviso) <div class="alert alert-warning">{{ $scanAviso }}</div> @endif

              <div class="row g-2 mb-2">
                <div class="col-md-4">
                  @include('pages::radar._partials.escanear-codigo', ['campoAlvo' => 'transferenciaLocalOrigemId', 'tipoEsperado' => 'local', 'label' => 'Local de origem'])
                </div>
                <div class="col-md-4">
                  @include('pages::radar._partials.escanear-codigo', ['campoAlvo' => 'transferenciaMaterialId', 'campoUnidadeAlvo' => 'transferenciaUnidadeId', 'label' => 'Material/Lote/Serial'])
                </div>
                <div class="col-md-4">
                  @include('pages::radar._partials.escanear-codigo', ['campoAlvo' => 'transferenciaLocalDestinoId', 'tipoEsperado' => 'local', 'label' => 'Local de destino'])
                </div>
              </div>

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Material</label>
                  <select class="form-select" wire:model.live="transferenciaMaterialId">
                    <option value="">Selecione...</option>
                    @foreach ($this->materiaisAtivosParaSaida as $m)
                      <option value="{{ $m->id }}">{{ $m->codigo }} — {{ $m->descricao }}</option>
                    @endforeach
                  </select>
                  @error('transferenciaMaterialId') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6"></div>

                <div class="col-md-6">
                  <label class="form-label">Local de origem</label>
                  <select class="form-select" wire:model.live="transferenciaLocalOrigemId">
                    <option value="">Selecione...</option>
                    @foreach ($this->locaisOrigemParaTransferencia as $l)
                      <option value="{{ $l->id }}">{{ $l->nome }}</option>
                    @endforeach
                  </select>
                  @error('transferenciaLocalOrigemId') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                  <label class="form-label">Local de destino</label>
                  <select class="form-select" wire:model.live="transferenciaLocalDestinoId">
                    <option value="">Selecione...</option>
                    @foreach ($this->locaisDestinoParaTransferencia as $l)
                      <option value="{{ $l->id }}">{{ $l->nome }}</option>
                    @endforeach
                  </select>
                  @error('transferenciaLocalDestinoId') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                @if ($this->unidadesDisponiveisParaTransferencia->isNotEmpty() || $transferenciaUnidadeId)
                  <div class="col-md-6">
                    <label class="form-label">Lote/Bobina/Serial</label>
                    <select class="form-select" wire:model.live="transferenciaUnidadeId">
                      <option value="">Selecione...</option>
                      @foreach ($this->unidadesDisponiveisParaTransferencia as $x)
                        <option value="{{ $x['unidade']->id }}">
                          {{ $x['unidade']->codigo_lote ?? $x['unidade']->serial_unico }} — saldo na origem {{ number_format($x['saldo'], 3, ',', '.') }}
                        </option>
                      @endforeach
                    </select>
                  </div>
                @endif

                <div class="col-md-6">
                  <label class="form-label">Quantidade</label>
                  <input type="number" step="0.001" class="form-control" wire:model.live="transferenciaQuantidade">
                  @error('transferenciaQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
                  @if (! is_null($this->saldoOrigemPreviewTransferencia))
                    <small class="text-muted">Saldo físico na origem: {{ number_format($this->saldoOrigemPreviewTransferencia, 3, ',', '.') }}</small>
                  @endif
                </div>

                <div class="col-md-6">
                  <label class="form-label">Data da transferência</label>
                  <input type="date" class="form-control" wire:model="transferenciaData">
                  @error('transferenciaData') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                  <label class="form-label">Observação (opcional)</label>
                  <textarea class="form-control" wire:model="transferenciaObservacao" rows="2"></textarea>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalTransferencia">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarTransferencia">Transferir</button>
            </div>
          </div>
        </div>
      </div>
    @endif
  @endif

  @if ($abaAtiva === 'movimentacoes')
    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Histórico de Movimentações</h5>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Data</th>
              <th>Tipo</th>
              <th>Material</th>
              <th>Local</th>
              <th>Lote/Serial</th>
              <th>Quantidade</th>
              <th>Pacote</th>
              <th>Frente informada</th>
              <th>Retirado por</th>
              <th>Registrado por</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($this->movimentacoes as $mov)
              <tr wire:key="mov-{{ $mov->id }}">
                <td>{{ $mov->ocorrido_em->format('d/m/Y') }}</td>
                <td>
                  @if ($mov->tipo->value === 'saida')
                    <span class="badge bg-label-danger">{{ $mov->tipo->label() }}</span>
                  @else
                    <span class="badge bg-label-success">{{ $mov->tipo->label() }}</span>
                  @endif
                </td>
                <td>{{ $mov->material?->codigo }}</td>
                <td>{{ $mov->localEstoque?->nome }}</td>
                <td>{{ $mov->unidadeEstoque?->codigo_lote ?? $mov->unidadeEstoque?->serial_unico ?? '—' }}</td>
                <td>{{ $mov->tipo->value === 'saida' ? '-' : '+' }}{{ number_format((float) $mov->quantidade, 3, ',', '.') }}</td>
                <td>{{ $mov->pacote?->codigo ?? $mov->pacote?->nome ?? '—' }}</td>
                <td>{{ $mov->frenteTrabalho?->nome ?? ($mov->tipo->value === 'saida' ? 'Pendente de conciliação' : '—') }}</td>
                <td>
                  @if ($mov->retirado_por_externo)
                    {{ $mov->retirado_por_externo }} <small class="text-muted">(externo)</small>
                  @elseif ($mov->retiradoPorUsuario)
                    {{ $mov->retiradoPorUsuario->first_name }} {{ $mov->retiradoPorUsuario->last_name }}
                  @else
                    —
                  @endif
                </td>
                <td>{{ $mov->registradoPor?->first_name ? $mov->registradoPor->first_name . ' ' . $mov->registradoPor->last_name : 'Usuário removido' }}</td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center text-muted py-4">Nenhuma movimentação registrada ainda.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  @endif

  {{-- ===================== PLANEJAMENTO / RESERVAS (Ciclo 20, Etapa 20.2) ===================== --}}
  @if ($abaAtiva === 'planejamento')
    <div class="card mb-3">
      <div class="card-header">
        <h5 class="mb-0">Demanda por Pacote e Material</h5>
        <small class="text-muted">Demanda formal (alocada via Requisições do Planejamento), quanto já foi destinado a uma Frente de Trabalho, e o saldo ainda sem rateio.</small>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Pacote</th>
              <th>Material</th>
              <th>Demanda Formal</th>
              <th>Destinado</th>
              <th>Sem Destinação</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($this->paresPacoteMaterial as $par)
              <tr wire:key="par-{{ $par['item_suprimento_id'] }}-{{ $par['material_id'] }}">
                <td>{{ $par['pacote']?->codigo ?? $par['pacote']?->nome ?? '—' }}</td>
                <td>{{ $par['material']?->codigo }} — {{ $par['material']?->descricao }}</td>
                <td>{{ number_format($par['formal'], 3, ',', '.') }}</td>
                <td>{{ number_format($par['destinado'], 3, ',', '.') }}</td>
                <td>
                  @if ($par['saldo_a_destinar'] > 0.0005)
                    <span class="badge bg-label-warning">{{ number_format($par['saldo_a_destinar'], 3, ',', '.') }} pendente</span>
                  @else
                    <span class="badge bg-label-success">Totalmente destinado</span>
                  @endif
                </td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-primary" wire:click="abrirModalDestinacao(null, '{{ $par['item_suprimento_id'] }}', '{{ $par['material_id'] }}')">
                    Nova Destinação
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirModalReserva(null, '{{ $par['item_suprimento_id'] }}', '{{ $par['material_id'] }}')">
                    Reservar
                  </button>
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted py-4">Nenhum Pacote com demanda formal alocada nesta obra ainda.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">
        <h5 class="mb-0">Destinações Planejadas</h5>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Pacote</th>
              <th>Material</th>
              <th>Frente</th>
              <th>Planejado</th>
              <th>Reservado (Ativo)</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($this->destinacoesPlanejadas as $linha)
              <tr wire:key="destinacao-{{ $linha['destinacao']->id }}">
                <td>{{ $linha['destinacao']->pacote?->codigo ?? $linha['destinacao']->pacote?->nome ?? '—' }}</td>
                <td>{{ $linha['destinacao']->material?->codigo }}</td>
                <td>
                  {{ $linha['destinacao']->frenteTrabalho?->nome ?? '—' }}
                  @if ($linha['destinacao']->frenteTrabalho?->trashed())
                    <span class="badge bg-label-secondary">Arquivada</span>
                  @endif
                </td>
                <td>{{ number_format((float) $linha['destinacao']->quantidade_planejada, 3, ',', '.') }}</td>
                <td>{{ number_format($linha['reservado_ativo'], 3, ',', '.') }}</td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-icon" wire:click="abrirModalDestinacao('{{ $linha['destinacao']->id }}')" title="Editar">
                    <i class="bx bx-edit"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirModalReserva('{{ $linha['destinacao']->id }}')">
                    Reservar
                  </button>
                  <button type="button" class="btn btn-sm btn-icon" onclick="confirmarAcao(this, { mensagem: 'Excluir esta Destinação Planejada?', metodo: 'excluirDestinacao', args: ['{{ $linha['destinacao']->id }}'], corBotao: 'danger', icone: 'bx-trash' })" title="Excluir">
                    <i class="bx bx-trash text-danger"></i>
                  </button>
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma Destinação Planejada cadastrada ainda.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Reservas de Estoque</h5>
        {{-- Ciclo 20.2.CORREÇÃO (Achado B1): toda Reserva agora exige um
             Pacote conhecido — a criação parte sempre de uma linha de
             "Demanda por Pacote e Material" (botão "Reservar" acima) ou
             de uma Destinação Planejada já existente, nunca mais de um
             botão às cegas aqui. --}}
        <small class="text-muted">Para reservar, use o botão "Reservar" na tabela de Demanda acima ou em uma Destinação Planejada.</small>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Pacote</th>
              <th>Material</th>
              <th>Local</th>
              <th>Lote/Serial</th>
              <th>Destinação</th>
              <th>Quantidade</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($this->reservasEstoque as $reserva)
              <tr wire:key="reserva-{{ $reserva->id }}">
                <td>{{ $reserva->pacote?->codigo ?? $reserva->pacote?->nome ?? '—' }}</td>
                <td>{{ $reserva->material?->codigo }}</td>
                <td>{{ $reserva->localEstoque?->nome }}</td>
                <td>{{ $reserva->unidadeEstoque?->codigo_lote ?? $reserva->unidadeEstoque?->serial_unico ?? '—' }}</td>
                <td>{{ $reserva->destinacaoPlanejada?->frenteTrabalho?->nome ?? 'Sem destinação definida' }}</td>
                <td>{{ number_format((float) $reserva->quantidade, 3, ',', '.') }}</td>
                <td>
                  @if ($reserva->estaAtiva())
                    <span class="badge bg-label-success">Ativa</span>
                  @else
                    <span class="badge bg-label-secondary">Liberada</span>
                  @endif
                </td>
                <td class="text-end">
                  @if ($reserva->estaAtiva())
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirModalLiberar('{{ $reserva->id }}')">Liberar</button>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma reserva de estoque registrada ainda.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    @if ($modalDestinacaoAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">{{ $editandoDestinacaoId ? 'Editar' : 'Nova' }} Destinação Planejada</h5>
              <button type="button" class="btn-close" wire:click="fecharModalDestinacao"></button>
            </div>
            <div class="modal-body">
              @error('destinacaoGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror

              <div class="mb-3">
                <label class="form-label">Frente de Trabalho</label>
                <select class="form-select" wire:model="destinacaoFrenteId" @if ($editandoDestinacaoId) disabled @endif>
                  <option value="">Selecione...</option>
                  @foreach ($this->frentesTrabalho as $frente)
                    <option value="{{ $frente->id }}">{{ $frente->nome }}</option>
                  @endforeach
                </select>
                @error('destinacaoFrenteId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>

              <div class="mb-3">
                <label class="form-label">Quantidade Planejada</label>
                <input type="number" step="0.001" class="form-control" wire:model="destinacaoQuantidade">
                @error('destinacaoQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalDestinacao">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarDestinacao">Salvar</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    @if ($modalReservaAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Nova Reserva de Estoque</h5>
              <button type="button" class="btn-close" wire:click="fecharModalReserva"></button>
            </div>
            <div class="modal-body">
              @error('reservaGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              @if ($scanErro) <div class="alert alert-warning">{{ $scanErro }}</div> @endif

              {{-- Ciclo 20.8 — só o Local é escaneável aqui: Pacote/Material
                   já chegam FIXOS pela Destinação/Demanda (20.2.CORREÇÃO,
                   Achado B1), nunca um select livre. --}}
              @include('pages::radar._partials.escanear-codigo', ['campoAlvo' => 'reservaLocalId', 'tipoEsperado' => 'local', 'label' => 'Local'])

              {{-- Ciclo 20.2.CORREÇÃO (Achado B1): Pacote e Material são
                   SEMPRE conhecidos ao chegar aqui (via Destinação ou via
                   a linha "Demanda por Pacote e Material") — nunca mais
                   um select às cegas. --}}
              <div class="mb-3">
                <label class="form-label">Pacote de Compra</label>
                <input type="text" class="form-control" value="{{ \App\Models\ItemSuprimento::find($reservaPacoteId)?->codigo ?? \App\Models\ItemSuprimento::find($reservaPacoteId)?->nome }}" disabled>
              </div>

              <div class="mb-3">
                <label class="form-label">Material</label>
                <input type="text" class="form-control" value="{{ \App\Models\Material::find($reservaMaterialId)?->codigo }} — {{ \App\Models\Material::find($reservaMaterialId)?->descricao }}" disabled>
                @error('reservaPacoteId') <div class="text-danger small">{{ $message }}</div> @enderror
                @error('reservaMaterialId') <div class="text-danger small">{{ $message }}</div> @enderror
                @unless ($reservaDestinacaoId)
                  <small class="text-muted">Sem destinação detalhada — a reserva fica vinculada só ao Pacote/Material, pendente de rateio por Frente.</small>
                @endunless
              </div>

              <div class="mb-3">
                <label class="form-label">Local de Estoque</label>
                <select class="form-select" wire:model.live="reservaLocalId">
                  <option value="">Selecione...</option>
                  @foreach ($this->locais->where('ativo', true) as $local)
                    <option value="{{ $local->id }}">{{ $local->nome }}</option>
                  @endforeach
                </select>
                @error('reservaLocalId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>

              @if ($this->unidadesDisponiveisParaReserva->isNotEmpty())
                <div class="mb-3">
                  <label class="form-label">Lote/Bobina/Serial</label>
                  <select class="form-select" wire:model.live="reservaUnidadeId">
                    <option value="">Selecione...</option>
                    @foreach ($this->unidadesDisponiveisParaReserva as $u)
                      <option value="{{ $u['unidade']->id }}">
                        {{ $u['unidade']->codigo_lote ?? $u['unidade']->serial_unico }} — disponível {{ number_format($u['disponivel'], 3, ',', '.') }}
                      </option>
                    @endforeach
                  </select>
                </div>
              @endif

              <div class="mb-3">
                <label class="form-label">Quantidade</label>
                <input type="number" step="0.001" class="form-control" wire:model="reservaQuantidade">
                @error('reservaQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
                @if (! is_null($this->saldoDisponivelPreviewReserva))
                  <small class="text-muted">Saldo físico disponível: {{ number_format($this->saldoDisponivelPreviewReserva, 3, ',', '.') }}</small>
                @elseif ($reservaLocalId)
                  <small class="text-danger">Não há saldo físico disponível para reservar.</small>
                @endif
              </div>

              <div class="mb-3">
                <label class="form-label">Observação (opcional)</label>
                <textarea class="form-control" wire:model="reservaObservacao" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalReserva">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarReserva">Reservar</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    @if ($modalLiberarAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Liberar Reserva</h5>
              <button type="button" class="btn-close" wire:click="fecharModalLiberar"></button>
            </div>
            <div class="modal-body">
              @error('liberarGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <div class="mb-3">
                <label class="form-label">Motivo (opcional)</label>
                <textarea class="form-control" wire:model="liberarMotivo" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalLiberar">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarLiberarReserva">Confirmar Liberação</button>
            </div>
          </div>
        </div>
      </div>
    @endif
  @endif

  {{-- ===================== CONCILIAÇÃO / APLICAÇÃO (20.4) ===================== --}}
  @if ($abaAtiva === 'conciliacao')
    @if ($this->coberturaDeficitPorObra->isNotEmpty())
      <div class="card mb-3">
        <div class="card-header">
          <h5 class="mb-0">Cobertura de Reservas / Reposição Necessária</h5>
          <small class="text-muted">Físico não cobre tudo que está reservado — déficit agregado, nunca atribuído automaticamente a uma Reserva/Frente específica.</small>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Material</th>
                <th>Local</th>
                <th class="text-end">Físico</th>
                <th class="text-end">Reservado Ativo</th>
                <th class="text-end">Déficit (reposição necessária)</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($this->coberturaDeficitPorObra as $linha)
                <tr wire:key="deficit-{{ $linha['material_id'] }}-{{ $linha['local_estoque_id'] }}">
                  <td>{{ $linha['material']->codigo }} — {{ $linha['material']->descricao }}</td>
                  <td>{{ $linha['local']->nome }}</td>
                  <td class="text-end">{{ number_format($linha['fisico'], 3, ',', '.') }}</td>
                  <td class="text-end">{{ number_format($linha['reservado_ativo'], 3, ',', '.') }}</td>
                  <td class="text-end text-danger fw-bold">{{ number_format($linha['deficit'], 3, ',', '.') }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    @endif

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Saídas Pendentes de Conciliação</h5>
        <small class="text-muted">Onde o material efetivamente foi utilizado — a Saída física nunca é reescrita; cada linha abaixo pode ser conciliada em uma ou várias Frentes/Pacotes.</small>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>Data</th>
              <th>Material</th>
              <th>Lote/Serial</th>
              <th class="text-end">Qtd. Saída</th>
              <th>Reserva/Pacote</th>
              <th>Frente Planejada</th>
              <th>Frente Informada</th>
              <th class="text-end">Conciliado</th>
              <th class="text-end">Pendente</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @forelse ($this->saidasComPendencia as $linha)
              <tr wire:key="pend-{{ $linha['saida']->id }}">
                <td>{{ $linha['saida']->ocorrido_em->format('d/m/Y') }}</td>
                <td>{{ $linha['saida']->material->codigo }} — {{ $linha['saida']->material->descricao }}</td>
                <td>{{ $linha['saida']->unidadeEstoque?->codigo_lote ?? $linha['saida']->unidadeEstoque?->serial_unico ?? '—' }}</td>
                <td class="text-end">{{ number_format($linha['saida']->quantidade, 3, ',', '.') }}</td>
                <td>{{ $linha['saida']->pacote?->codigo ?? $linha['saida']->pacote?->nome ?? '—' }}</td>
                <td>{{ $linha['saida']->reservaEstoque?->destinacaoPlanejada?->frenteTrabalho?->nome ?? '—' }}</td>
                <td>{{ $linha['saida']->frenteTrabalho?->nome ?? '—' }}</td>
                <td class="text-end">{{ number_format($linha['aplicado'], 3, ',', '.') }}</td>
                <td class="text-end text-warning fw-bold">{{ number_format($linha['pendente'], 3, ',', '.') }}</td>
                <td>
                  <button type="button" class="btn btn-sm btn-primary" wire:click="abrirModalConciliacao('{{ $linha['saida']->id }}')">Conciliar</button>
                </td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center text-muted py-4">Nenhuma Saída com pendência de conciliação.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    @if ($modalAplicacaoAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Conciliar Saída — Onde o material foi aplicado</h5>
              <button type="button" class="btn-close" wire:click="fecharModalConciliacao"></button>
            </div>
            <div class="modal-body">
              @error('aplicacaoGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror

              @if ($this->saidaEmConciliacao)
                <div class="alert alert-light border mb-3">
                  <strong>{{ $this->saidaEmConciliacao->material->codigo }} — {{ $this->saidaEmConciliacao->material->descricao }}</strong><br>
                  Saída de {{ number_format($this->saidaEmConciliacao->quantidade, 3, ',', '.') }} em {{ $this->saidaEmConciliacao->ocorrido_em->format('d/m/Y') }} —
                  Local: {{ $this->saidaEmConciliacao->localEstoque?->nome }}
                  @if ($this->saidaEmConciliacao->unidadeEstoque)
                    — {{ $this->saidaEmConciliacao->unidadeEstoque->codigo_lote ?? $this->saidaEmConciliacao->unidadeEstoque->serial_unico }}
                  @endif
                  <br>
                  Pendente de conciliação: <strong class="{{ $this->saidaEmConciliacaoFechada ? 'text-success' : 'text-warning' }}">{{ number_format($this->pendenteDaSaidaEmConciliacao ?? 0, 3, ',', '.') }}</strong>
                  @if ($this->saidaEmConciliacaoFechada)
                    <span class="badge bg-label-success ms-2"><i class="bx bx-lock-alt"></i> 100% conciliada</span>
                  @endif
                </div>

                @if ($this->desvioDaSaidaEmConciliacao && $this->desvioDaSaidaEmConciliacao['tem_frente_planejada'])
                  <div class="alert alert-info small mb-3">
                    <strong>Desvio diretamente rastreável</strong> (esta Saída consome uma Reserva com Frente planejada):
                    aderente = {{ number_format($this->desvioDaSaidaEmConciliacao['aderente'], 3, ',', '.') }},
                    desviado para outra(s) Frente(s) = {{ number_format($this->desvioDaSaidaEmConciliacao['total_desviado'], 3, ',', '.') }}.
                  </div>
                @endif

                <table class="table table-sm">
                  <thead>
                    <tr><th>Frente</th><th>Pacote</th><th class="text-end">Qtd.</th><th>Data</th><th>Obs.</th><th></th></tr>
                  </thead>
                  <tbody>
                    @forelse ($this->aplicacoesDaSaidaEmConciliacao as $ap)
                      <tr wire:key="ap-{{ $ap->id }}">
                        <td>{{ $ap->frenteTrabalho?->nome ?? '—' }}</td>
                        <td>{{ $ap->pacote?->codigo ?? $ap->pacote?->nome ?? '—' }}</td>
                        <td class="text-end">{{ number_format($ap->quantidade, 3, ',', '.') }}</td>
                        <td>{{ $ap->aplicado_em->format('d/m/Y') }}</td>
                        <td class="small text-muted">{{ $ap->observacao }}</td>
                        <td>
                          @unless ($this->saidaEmConciliacaoFechada)
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="editarLinhaAplicacao('{{ $ap->id }}')"><i class="bx bx-edit"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="excluirLinhaAplicacao('{{ $ap->id }}')"><i class="bx bx-trash"></i></button>
                          @endunless
                        </td>
                      </tr>
                    @empty
                      <tr><td colspan="6" class="text-center text-muted">Nenhuma aplicação registrada ainda.</td></tr>
                    @endforelse
                  </tbody>
                </table>

                @unless ($this->saidaEmConciliacaoFechada)
                  <hr>
                  <h6>{{ $aplicacaoEditandoId ? 'Editar aplicação' : 'Nova aplicação' }}</h6>
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label">Frente real</label>
                      <select class="form-select" wire:model="aplicacaoFrenteId">
                        <option value="">Selecione...</option>
                        @foreach ($this->frentesParaAplicacao as $f)
                          <option value="{{ $f->id }}">{{ $f->nome }}</option>
                        @endforeach
                      </select>
                      @error('aplicacaoFrenteId') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Pacote/Demanda (opcional)</label>
                      <select class="form-select" wire:model="aplicacaoPacoteId" @if ($this->saidaEmConciliacao?->item_suprimento_id) disabled @endif>
                        <option value="">{{ $this->saidaEmConciliacao?->item_suprimento_id ? 'Herdado da Saída' : 'Ainda não identificado' }}</option>
                        @foreach ($this->pacotesParaAplicacao as $p)
                          <option value="{{ $p->id }}" @if ($this->saidaEmConciliacao?->item_suprimento_id === $p->id) selected @endif>{{ $p->codigo ?? $p->nome }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Quantidade</label>
                      <input type="number" step="0.001" class="form-control" wire:model="aplicacaoQuantidade">
                      @error('aplicacaoQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Data da aplicação</label>
                      <input type="date" class="form-control" wire:model="aplicacaoData">
                      @error('aplicacaoData') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                      <button type="button" class="btn btn-primary w-100" wire:click="confirmarAplicacao">
                        {{ $aplicacaoEditandoId ? 'Salvar alteração' : 'Adicionar aplicação' }}
                      </button>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Observação (opcional)</label>
                      <textarea class="form-control" wire:model="aplicacaoObservacao" rows="2"></textarea>
                    </div>
                    @if ($aplicacaoEditandoId)
                      <div class="col-12">
                        <button type="button" class="btn btn-sm btn-link" wire:click="cancelarEdicaoAplicacao">Cancelar edição</button>
                      </div>
                    @endif
                  </div>
                @endunless
              @endif
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalConciliacao">Fechar</button>
            </div>
          </div>
        </div>
      </div>
    @endif
  @endif
  {{-- ===================== INDUSTRIALIZAÇÃO EM TERCEIROS (20.5) ===================== --}}
  @if ($abaAtiva === 'industrializacao')
    @if (! $this->ordemIndustrDetalheId)
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-0">Ordens de Industrialização em Terceiros</h5>
            <small class="text-muted">Material enviado para fabricação/beneficiamento externo — remessa ≠ consumo/aplicação.</small>
          </div>
          <button type="button" class="btn btn-primary btn-sm" wire:click="abrirModalOrdemIndustr">
            <i class="bx bx-plus"></i> Nova Ordem
          </button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr><th>Nº</th><th>Fornecedor</th><th>Local de Custódia</th><th>Status</th><th>Produtos</th><th>Criada em</th><th></th></tr>
            </thead>
            <tbody>
              @forelse ($this->ordensIndustrializacao as $ordemLinha)
                <tr wire:key="ordemindustr-{{ $ordemLinha->id }}">
                  <td>{{ $ordemLinha->numero ?? '—' }}</td>
                  <td>{{ $ordemLinha->fornecedor?->nome }}</td>
                  <td>{{ $ordemLinha->localTerceiro?->nome }}</td>
                  <td>
                    @if ($ordemLinha->status?->value === 'rascunho')
                      <span class="badge bg-label-secondary">Rascunho</span>
                    @elseif ($ordemLinha->status?->value === 'emitida')
                      <span class="badge bg-label-primary">Emitida</span>
                    @else
                      <span class="badge bg-label-success">Concluída</span>
                    @endif
                  </td>
                  <td>{{ $ordemLinha->produtos()->count() }}</td>
                  <td>{{ $ordemLinha->created_at->format('d/m/Y') }}</td>
                  <td>
                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirDetalheOrdemIndustr('{{ $ordemLinha->id }}')">Detalhe</button>
                  </td>
                </tr>
              @empty
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma Ordem de Industrialização registrada.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    @else
      @php $ordemDet = $this->ordemIndustrDetalhe; @endphp
      @if ($ordemDet)
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div>
              <h5 class="mb-0">Ordem {{ $ordemDet->numero ? '#' . $ordemDet->numero : '(Rascunho)' }} — {{ $ordemDet->fornecedor?->nome }}</h5>
              <small class="text-muted">Local de custódia: {{ $ordemDet->localTerceiro?->nome }} @if ($ordemDet->pacote) — Pacote: {{ $ordemDet->pacote->codigo ?? $ordemDet->pacote->nome }} @endif</small>
            </div>
            <div>
              @if ($ordemDet->status?->value === 'rascunho')
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirModalProdutoIndustr">Adicionar Produto</button>
                <button type="button" class="btn btn-sm btn-primary" wire:click="confirmarEmitirOrdemIndustr">Emitir Ordem</button>
              @endif
              <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="fecharDetalheOrdemIndustr">Voltar</button>
            </div>
          </div>
          <div class="card-body">
            @error('ordemIndustrDetalheGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror

            <h6>Produtos Previstos</h6>
            <div class="table-responsive mb-3">
              <table class="table table-sm">
                <thead>
                  <tr><th>Material</th><th>Documento</th><th class="text-end">Previsto</th><th class="text-end">Produzido</th><th class="text-end">Entregue</th><th class="text-end">Pronto</th><th></th></tr>
                </thead>
                <tbody>
                  @forelse ($this->produtosDaOrdemDetalheComSaldo as $linhaProduto)
                    <tr wire:key="produtoindustr-{{ $linhaProduto['produto']->id }}">
                      <td>{{ $linhaProduto['produto']->material->codigo }} — {{ $linhaProduto['produto']->material->descricao }}</td>
                      <td>{{ $linhaProduto['produto']->documentoRevisao?->documento?->codigo ?? '—' }} {{ $linhaProduto['produto']->documentoRevisao?->revisao }}</td>
                      <td class="text-end">{{ number_format($linhaProduto['produto']->quantidade_prevista, 3, ',', '.') }}</td>
                      <td class="text-end">{{ number_format($linhaProduto['produzido'], 3, ',', '.') }}</td>
                      <td class="text-end">{{ number_format($linhaProduto['entregue'], 3, ',', '.') }}</td>
                      <td class="text-end fw-bold">{{ number_format($linhaProduto['saldo_pronto'], 3, ',', '.') }}</td>
                      <td>
                        @if ($ordemDet->status?->value === 'emitida')
                          <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirModalProducaoIndustr('{{ $linhaProduto['produto']->id }}')">Produção</button>
                          <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirModalConsumoIndustr('{{ $linhaProduto['produto']->id }}')">Consumo</button>
                          <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirModalEntregaIndustr('{{ $linhaProduto['produto']->id }}')">Entrega</button>
                        @endif
                      </td>
                    </tr>
                  @empty
                    <tr><td colspan="7" class="text-center text-muted">Nenhum produto previsto ainda.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>

            @if ($ordemDet->status?->value === 'emitida' || $ordemDet->status?->value === 'concluida')
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Remessas de Matéria-Prima</h6>
                <div>
                  <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirModalRemessaIndustr('envio')">Registrar Envio</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirModalRemessaIndustr('retorno_sobra')">Registrar Retorno de Sobra</button>
                </div>
              </div>
              <div class="table-responsive mb-3">
                <table class="table table-sm">
                  <thead>
                    <tr><th>Data</th><th>Material</th><th>Direção</th><th class="text-end">Quantidade</th><th>Lote/Serial</th></tr>
                  </thead>
                  <tbody>
                    @forelse ($ordemDet->remessas as $remessaLinha)
                      <tr wire:key="remessaindustr-{{ $remessaLinha->id }}">
                        <td>{{ $remessaLinha->ocorrido_em->format('d/m/Y') }}</td>
                        <td>{{ $remessaLinha->material->codigo }}</td>
                        <td>{{ $remessaLinha->direcao->label() }}</td>
                        <td class="text-end">{{ number_format($remessaLinha->quantidade, 3, ',', '.') }}</td>
                        <td>{{ $remessaLinha->unidadeEstoque?->codigo_lote ?? $remessaLinha->unidadeEstoque?->serial_unico ?? '—' }}</td>
                      </tr>
                    @empty
                      <tr><td colspan="5" class="text-center text-muted">Nenhuma remessa registrada ainda.</td></tr>
                    @endforelse
                  </tbody>
                </table>
              </div>

              @if ($this->saldoMateriaPrimaOrdemDetalhe->isNotEmpty())
                <h6>Matéria-Prima em Custódia do Terceiro</h6>
                <div class="table-responsive">
                  <table class="table table-sm mb-0">
                    <thead>
                      <tr><th>Material</th><th class="text-end">Enviado</th><th class="text-end">Consumido</th><th class="text-end">Devolvido</th><th class="text-end">Saldo em Terceiro</th><th class="text-end">Diferença (não classificada)</th></tr>
                    </thead>
                    <tbody>
                      @foreach ($this->saldoMateriaPrimaOrdemDetalhe as $linhaSaldo)
                        <tr wire:key="saldomp-{{ $linhaSaldo['material']->id }}">
                          <td>{{ $linhaSaldo['material']->codigo }}</td>
                          <td class="text-end">{{ number_format($linhaSaldo['enviado'], 3, ',', '.') }}</td>
                          <td class="text-end">{{ number_format($linhaSaldo['consumido'], 3, ',', '.') }}</td>
                          <td class="text-end">{{ number_format($linhaSaldo['devolvido'], 3, ',', '.') }}</td>
                          <td class="text-end fw-bold">{{ number_format($linhaSaldo['saldo_em_terceiro'], 3, ',', '.') }}</td>
                          <td class="text-end text-muted">{{ number_format($linhaSaldo['diferenca_nao_classificada'], 3, ',', '.') }}</td>
                        </tr>
                      @endforeach
                    </tbody>
                  </table>
                </div>
              @endif
            @endif
          </div>
        </div>
      @endif
    @endif

    {{-- Modal: Nova Ordem --}}
    @if ($modalOrdemIndustrAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Nova Ordem de Industrialização</h5>
              <button type="button" class="btn-close" wire:click="fecharModalOrdemIndustr"></button>
            </div>
            <div class="modal-body">
              @error('ordemIndustrGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <div class="mb-3">
                <label class="form-label">Fornecedor</label>
                <select class="form-select" wire:model.live="ordemIndustrFornecedorId">
                  <option value="">Selecione...</option>
                  @foreach ($this->fornecedoresParaIndustr as $f)
                    <option value="{{ $f->id }}">{{ $f->nome }}</option>
                  @endforeach
                </select>
                @error('ordemIndustrFornecedorId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Local de Custódia (Terceiro)</label>
                <select class="form-select" wire:model="ordemIndustrLocalTerceiroId">
                  <option value="">Selecione...</option>
                  @foreach ($this->locaisTerceiroParaIndustr as $l)
                    <option value="{{ $l->id }}">{{ $l->nome }}</option>
                  @endforeach
                </select>
                @error('ordemIndustrLocalTerceiroId') <div class="text-danger small">{{ $message }}</div> @enderror
                <small class="text-muted">Cadastre um Local de Estoque tipo Terceiro (vinculado a este Fornecedor) na aba Locais de Estoque, se ainda não existir.</small>
              </div>
              <div class="mb-3">
                <label class="form-label">Pacote de Compra (opcional)</label>
                <select class="form-select" wire:model="ordemIndustrPacoteId">
                  <option value="">Nenhum</option>
                  @foreach ($this->pacotesParaIndustr as $p)
                    <option value="{{ $p->id }}">{{ $p->codigo ?? $p->nome }}</option>
                  @endforeach
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Observação (opcional)</label>
                <textarea class="form-control" wire:model="ordemIndustrObservacao" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalOrdemIndustr">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarCriarOrdemIndustr">Criar Ordem</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Modal: Adicionar Produto --}}
    @if ($modalProdutoIndustrAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Adicionar Produto Previsto</h5>
              <button type="button" class="btn-close" wire:click="fecharModalProdutoIndustr"></button>
            </div>
            <div class="modal-body">
              @error('produtoIndustrGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <div class="mb-3">
                <label class="form-label">Material (Produto)</label>
                <select class="form-select" wire:model="produtoIndustrMaterialId">
                  <option value="">Selecione...</option>
                  @foreach ($this->materiaisParaIndustr as $m)
                    <option value="{{ $m->id }}">{{ $m->codigo }} — {{ $m->descricao }}</option>
                  @endforeach
                </select>
                @error('produtoIndustrMaterialId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Documento de Fabricação (opcional)</label>
                <select class="form-select" wire:model="produtoIndustrDocumentoRevisaoId">
                  <option value="">Nenhum</option>
                  @foreach ($this->documentosRevisaoParaIndustr as $r)
                    <option value="{{ $r->id }}">{{ $r->documento?->codigo }} — {{ $r->revisao }}</option>
                  @endforeach
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Quantidade Prevista</label>
                <input type="number" step="0.001" class="form-control" wire:model="produtoIndustrQuantidadePrevista">
                @error('produtoIndustrQuantidadePrevista') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Observação (opcional)</label>
                <textarea class="form-control" wire:model="produtoIndustrObservacao" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalProdutoIndustr">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarAdicionarProdutoIndustr">Adicionar</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Modal: Remessa --}}
    @if ($modalRemessaIndustrAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">{{ $remessaIndustrDirecao === 'envio' ? 'Registrar Envio ao Terceiro' : 'Registrar Retorno de Sobra' }}</h5>
              <button type="button" class="btn-close" wire:click="fecharModalRemessaIndustr"></button>
            </div>
            <div class="modal-body">
              @error('remessaIndustrGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <div class="mb-3">
                <label class="form-label">Material</label>
                <select class="form-select" wire:model.live="remessaIndustrMaterialId">
                  <option value="">Selecione...</option>
                  @foreach ($this->materiaisParaIndustr as $m)
                    <option value="{{ $m->id }}">{{ $m->codigo }} — {{ $m->descricao }}</option>
                  @endforeach
                </select>
                @error('remessaIndustrMaterialId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Local Próprio (origem/destino conforme direção)</label>
                <select class="form-select" wire:model.live="remessaIndustrLocalProprioId">
                  <option value="">Selecione...</option>
                  @foreach ($this->locaisProprioParaIndustr as $l)
                    <option value="{{ $l->id }}">{{ $l->nome }}</option>
                  @endforeach
                </select>
                @error('remessaIndustrLocalProprioId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              @if ($this->unidadesDisponiveisParaRemessa->isNotEmpty())
                <div class="mb-3">
                  <label class="form-label">Lote/Bobina/Serial</label>
                  <select class="form-select" wire:model="remessaIndustrUnidadeId">
                    <option value="">Selecione...</option>
                    @foreach ($this->unidadesDisponiveisParaRemessa as $x)
                      <option value="{{ $x['unidade']->id }}">{{ $x['unidade']->codigo_lote ?? $x['unidade']->serial_unico }} — disponível {{ number_format($x['saldo'], 3, ',', '.') }}</option>
                    @endforeach
                  </select>
                </div>
              @endif
              <div class="mb-3">
                <label class="form-label">Quantidade</label>
                <input type="number" step="0.001" class="form-control" wire:model="remessaIndustrQuantidade">
                @error('remessaIndustrQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Data</label>
                <input type="date" class="form-control" wire:model="remessaIndustrData">
                @error('remessaIndustrData') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Observação (opcional)</label>
                <textarea class="form-control" wire:model="remessaIndustrObservacao" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalRemessaIndustr">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarRemessaIndustr">Registrar</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Modal: Produção --}}
    @if ($modalProducaoIndustrAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Registrar Produção</h5>
              <button type="button" class="btn-close" wire:click="fecharModalProducaoIndustr"></button>
            </div>
            <div class="modal-body">
              @error('producaoIndustrGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <div class="mb-3">
                <label class="form-label">Quantidade Produzida</label>
                <input type="number" step="0.001" class="form-control" wire:model="producaoIndustrQuantidade">
                @error('producaoIndustrQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Data</label>
                <input type="date" class="form-control" wire:model="producaoIndustrData">
              </div>
              <div class="mb-3">
                <label class="form-label">Lote (se aplicável)</label>
                <input type="text" class="form-control" wire:model="producaoIndustrCodigoLote">
              </div>
              <div class="mb-3">
                <label class="form-label">Serial (se aplicável)</label>
                <input type="text" class="form-control" wire:model="producaoIndustrSerialUnico">
              </div>
              <div class="mb-3">
                <label class="form-label">Observação (opcional)</label>
                <textarea class="form-control" wire:model="producaoIndustrObservacao" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalProducaoIndustr">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarProducaoIndustr">Registrar</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Modal: Consumo --}}
    @if ($modalConsumoIndustrAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Registrar Consumo de Matéria-Prima</h5>
              <button type="button" class="btn-close" wire:click="fecharModalConsumoIndustr"></button>
            </div>
            <div class="modal-body">
              @error('consumoIndustrGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <div class="mb-3">
                <label class="form-label">Remessa de Origem (matéria-prima)</label>
                <select class="form-select" wire:model="consumoIndustrRemessaId">
                  <option value="">Selecione...</option>
                  @foreach ($this->remessasEnvioParaConsumo as $x)
                    <option value="{{ $x['remessa']->id }}">{{ $x['remessa']->material->codigo }} — pendente {{ number_format($x['pendente'], 3, ',', '.') }}</option>
                  @endforeach
                </select>
                @error('consumoIndustrRemessaId') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Quantidade Consumida</label>
                <input type="number" step="0.001" class="form-control" wire:model="consumoIndustrQuantidade">
                @error('consumoIndustrQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
              </div>
              <div class="mb-3">
                <label class="form-label">Data</label>
                <input type="date" class="form-control" wire:model="consumoIndustrData">
              </div>
              <div class="mb-3">
                <label class="form-label">Observação (opcional)</label>
                <textarea class="form-control" wire:model="consumoIndustrObservacao" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalConsumoIndustr">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarConsumoIndustr">Registrar</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Modal: Entrega --}}
    @if ($modalEntregaIndustrAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Registrar Entrega</h5>
              <button type="button" class="btn-close" wire:click="fecharModalEntregaIndustr"></button>
            </div>
            <div class="modal-body">
              @error('entregaIndustrGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Quantidade</label>
                  <input type="number" step="0.001" class="form-control" wire:model="entregaIndustrQuantidade">
                  @error('entregaIndustrQuantidade') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                  <label class="form-label">Modalidade</label>
                  <select class="form-select" wire:model.live="entregaIndustrModalidade">
                    <option value="retorno_estoque_obra">Retorno ao estoque da obra</option>
                    <option value="entrega_direta_campo">Entrega direta ao campo</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Local de destino (próprio da obra)</label>
                  <select class="form-select" wire:model="entregaIndustrLocalDestinoId">
                    <option value="">Selecione...</option>
                    @foreach ($this->locaisProprioParaIndustr as $l)
                      <option value="{{ $l->id }}">{{ $l->nome }}</option>
                    @endforeach
                  </select>
                  @error('entregaIndustrLocalDestinoId') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                  <label class="form-label">Data</label>
                  <input type="date" class="form-control" wire:model="entregaIndustrData">
                </div>

                @if ($entregaIndustrModalidade === 'entrega_direta_campo')
                  <div class="col-md-6">
                    <label class="form-label">Frente de Trabalho</label>
                    <select class="form-select" wire:model="entregaIndustrFrenteId">
                      <option value="">Selecione...</option>
                      @foreach ($this->frentesParaIndustr as $f)
                        <option value="{{ $f->id }}">{{ $f->nome }}</option>
                      @endforeach
                    </select>
                    @error('entregaIndustrFrenteId') <div class="text-danger small">{{ $message }}</div> @enderror
                  </div>
                  <div class="col-12">
                    <label class="form-label">Quem retirou o material?</label>
                    <div class="d-flex gap-3 mb-2">
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="entregaIndustrTipoRetirante" id="entregaIndustrTipoRetiranteInterno" value="interno" wire:model.live="entregaIndustrTipoRetirante">
                        <label class="form-check-label" for="entregaIndustrTipoRetiranteInterno">Usuário do sistema</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="entregaIndustrTipoRetirante" id="entregaIndustrTipoRetiranteExterno" value="externo" wire:model.live="entregaIndustrTipoRetirante">
                        <label class="form-check-label" for="entregaIndustrTipoRetiranteExterno">Pessoa externa</label>
                      </div>
                    </div>
                    @if ($entregaIndustrTipoRetirante === 'interno')
                      <select class="form-select" wire:model="entregaIndustrRetiradoPorId">
                        <option value="">Selecione...</option>
                        @foreach ($this->usuariosDaObraParaIndustr as $u)
                          <option value="{{ $u->id }}">{{ $u->first_name }} {{ $u->last_name }}</option>
                        @endforeach
                      </select>
                      @error('entregaIndustrRetiradoPorId') <div class="text-danger small">{{ $message }}</div> @enderror
                    @else
                      <input type="text" class="form-control" wire:model="entregaIndustrRetiradoPorExterno" placeholder="Nome de quem retirou">
                      @error('entregaIndustrRetiradoPorExterno') <div class="text-danger small">{{ $message }}</div> @enderror
                    @endif
                  </div>
                @endif

                <div class="col-12">
                  <label class="form-label">Observação (opcional)</label>
                  <textarea class="form-control" wire:model="entregaIndustrObservacao" rows="2"></textarea>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalEntregaIndustr">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarEntregaIndustr">Registrar Entrega</button>
            </div>
          </div>
        </div>
      </div>
    @endif
  @endif

  {{-- ===================== INVENTÁRIO (Ciclo 20, Etapa 20.7) ===================== --}}
  @if ($abaAtiva === 'inventario')
    @if (! $this->inventarioDetalhe)
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Inventário Físico</h5>
          <button type="button" class="btn btn-primary" wire:click="abrirModalNovoInventario">
            <i class="bx bx-plus me-1"></i> Novo Inventário
          </button>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Número</th>
                <th>Título</th>
                <th>Local</th>
                <th>Status</th>
                <th>Contagem cega</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              @forelse ($this->inventariosEstoque as $inv)
                <tr wire:key="inv-{{ $inv->id }}">
                  <td>{{ $inv->numero ?? '—' }}</td>
                  <td>{{ $inv->titulo ?? '—' }}</td>
                  <td>{{ $inv->localEstoque?->nome }}</td>
                  <td>
                    <span class="badge
                      @if ($inv->status->value === 'concluido') bg-label-success
                      @elseif ($inv->status->value === 'cancelado') bg-label-secondary
                      @elseif ($inv->status->value === 'em_analise') bg-label-warning
                      @else bg-label-info @endif">
                      {{ $inv->status->label() }}
                    </span>
                  </td>
                  <td>{{ $inv->contagem_cega ? 'Sim' : 'Não' }}</td>
                  <td>
                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirInventarioDetalhe('{{ $inv->id }}')">Ver</button>
                  </td>
                </tr>
              @empty
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum Inventário registrado nesta obra ainda.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    @else
      @php
        $inv = $this->inventarioDetalhe;
      @endphp
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-0">Inventário {{ $inv->numero ? "#{$inv->numero}" : '(Rascunho)' }} — {{ $inv->titulo ?? $inv->localEstoque?->nome }}</h5>
            <small class="text-muted">Local: {{ $inv->localEstoque?->nome }} · Contagem cega: {{ $inv->contagem_cega ? 'Sim' : 'Não' }}</small>
          </div>
          <div class="d-flex gap-2 align-items-center">
            <span class="badge
              @if ($inv->status->value === 'concluido') bg-label-success
              @elseif ($inv->status->value === 'cancelado') bg-label-secondary
              @elseif ($inv->status->value === 'em_analise') bg-label-warning
              @else bg-label-info @endif">
              {{ $inv->status->label() }}
            </span>
            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="fecharInventarioDetalhe">Voltar</button>
          </div>
        </div>
        <div class="card-body">
          @error('inventarioDetalheGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
          @if ($scanErro) <div class="alert alert-warning">{{ $scanErro }}</div> @endif

          @if ($inv->status->value === 'rascunho')
            <p class="text-muted">Este Inventário ainda não foi iniciado — nenhum snapshot foi tirado. Ao iniciar, o sistema registra a foto do saldo atual de cada Material/Unidade neste Local.</p>
            <button type="button" class="btn btn-primary" wire:click="confirmarIniciarInventario">Iniciar Inventário</button>
          @else
            @if ($inv->status->value === 'em_contagem' || $inv->status->value === 'em_analise')
              <div style="max-width: 420px;">
                @include('pages::radar._partials.escanear-codigo', ['metodoCustom' => 'processarScanInventario', 'campoAlvo' => null, 'label' => 'item (Material ou Lote/Bobina/Serial)'])
              </div>
            @endif
            <div class="d-flex gap-2 mb-3">
              @if ($inv->status->value === 'em_contagem' || $inv->status->value === 'em_analise')
                <button type="button" class="btn btn-outline-primary btn-sm" wire:click="abrirModalItemInesperado">
                  <i class="bx bx-plus me-1"></i> Registrar serial inesperado
                </button>
              @endif
              @if ($inv->status->value === 'em_contagem')
                <button type="button" class="btn btn-warning btn-sm" wire:click="confirmarMoverParaAnalise">Mover para Análise</button>
              @endif
              @if ($inv->status->value === 'em_analise')
                <button type="button" class="btn btn-success btn-sm" wire:click="confirmarConcluirInventario">Concluir Inventário</button>
              @endif
              @if (in_array($inv->status->value, ['rascunho', 'em_contagem', 'em_analise']))
                <button type="button" class="btn btn-outline-danger btn-sm" wire:click="abrirModalCancelarInventario">Cancelar Inventário</button>
              @endif
            </div>

            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead>
                  <tr>
                    <th>Material</th>
                    <th>Lote/Serial</th>
                    @if (! ($inv->contagem_cega && $inv->status->value === 'em_contagem'))
                      <th class="text-end">Sistema</th>
                    @endif
                    <th class="text-end">Contado</th>
                    <th class="text-end">Diferença</th>
                    <th>Ajuste</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  @forelse ($this->itensInventarioDetalhe as $linha)
                    <tr wire:key="inv-item-{{ $linha['item']->id }}">
                      @php
                        $item = $linha['item'];
                      @endphp
                      <td>{{ $item->material?->codigo }} — {{ $item->material?->descricao }}</td>
                      <td>
                        @if ($item->ehSerialInesperado())
                          <span class="badge bg-label-warning">Inesperado: {{ $item->serial_texto_inesperado }}</span>
                        @else
                          {{ $item->unidadeEstoque?->codigo_lote ?? $item->unidadeEstoque?->serial_unico ?? '—' }}
                        @endif
                      </td>
                      @if (! ($inv->contagem_cega && $inv->status->value === 'em_contagem'))
                        <td class="text-end">{{ number_format((float) $item->quantidade_sistema_snapshot, 3, ',', '.') }}</td>
                      @endif
                      <td class="text-end">
                        {{ $linha['ultima_contagem'] ? number_format((float) $linha['ultima_contagem']->quantidade_contada, 3, ',', '.') : '— não contado —' }}
                      </td>
                      <td class="text-end">
                        @if (! is_null($linha['diferenca']))
                          <span class="badge @if ($linha['diferenca'] == 0) bg-label-success @elseif ($linha['diferenca'] > 0) bg-label-info @else bg-label-danger @endif">
                            {{ $linha['diferenca'] > 0 ? '+' : '' }}{{ number_format($linha['diferenca'], 3, ',', '.') }}
                          </span>
                        @else
                          —
                        @endif
                      </td>
                      <td>
                        @if ($linha['tem_ajuste'])
                          <span class="badge bg-label-success">Ajustado</span>
                        @endif
                      </td>
                      <td class="text-end">
                        @if ($inv->status->value === 'em_contagem' || $inv->status->value === 'em_analise')
                          <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirModalContagem('{{ $item->id }}')">
                            {{ $linha['ultima_contagem'] ? 'Recontar' : 'Contar' }}
                          </button>
                        @endif
                        @if ($inv->status->value === 'em_analise' && ! is_null($linha['diferenca']) && $linha['diferenca'] != 0 && ! $linha['tem_ajuste'] && ! $item->ehSerialInesperado())
                          <button type="button" class="btn btn-sm btn-outline-success" wire:click="abrirModalAjuste('{{ $item->id }}')">Aprovar Ajuste</button>
                        @endif
                      </td>
                    </tr>
                  @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">Nenhuma posição encontrada neste Local no momento do snapshot.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </div>
    @endif

    {{-- Modal: Novo Inventário --}}
    @if ($modalNovoInventarioAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Novo Inventário</h5>
              <button type="button" class="btn-close" wire:click="fecharModalNovoInventario"></button>
            </div>
            <div class="modal-body">
              @error('novoInventarioGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <div class="mb-3">
                <label class="form-label">Local de Estoque</label>
                <select class="form-select" wire:model="invLocalId">
                  <option value="">Selecione...</option>
                  @foreach ($this->locaisProprioAtivosParaInventario as $local)
                    <option value="{{ $local->id }}">{{ $local->nome }}</option>
                  @endforeach
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Título (opcional)</label>
                <input type="text" class="form-control" wire:model="invTitulo" placeholder="Ex.: Inventário mensal agosto/2026">
              </div>
              <div class="form-check">
                <input type="checkbox" class="form-check-input" wire:model="invContagemCega" id="invContagemCega">
                <label class="form-check-label" for="invContagemCega">Contagem cega (não mostrar saldo do sistema ao contador durante a contagem)</label>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalNovoInventario">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarNovoInventario">Criar</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Modal: Registrar Contagem --}}
    @if ($modalContagemItemId)
      <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Registrar Contagem</h5>
              <button type="button" class="btn-close" wire:click="fecharModalContagem"></button>
            </div>
            <div class="modal-body">
              @error('contagemGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <div class="mb-3">
                <label class="form-label">Quantidade encontrada</label>
                <input type="number" step="0.001" min="0" class="form-control" wire:model="contagemQuantidade">
              </div>
              <div class="mb-3">
                <label class="form-label">Data da contagem</label>
                <input type="date" class="form-control" wire:model="contagemData" max="{{ now()->toDateString() }}">
              </div>
              <div class="mb-3">
                <label class="form-label">Observação (opcional)</label>
                <textarea class="form-control" wire:model="contagemObservacao" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalContagem">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarContagem">Registrar</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Modal: Item Inesperado (serial) --}}
    @if ($modalItemInesperadoAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Registrar Serial Inesperado</h5>
              <button type="button" class="btn-close" wire:click="fecharModalItemInesperado"></button>
            </div>
            <div class="modal-body">
              @error('itemInesperadoGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <p class="text-muted small">Um serial físico encontrado que o sistema não esperava neste Local. Nenhuma Unidade de Estoque nova é criada — o registro fica pendente de investigação manual.</p>
              <div class="mb-3">
                <label class="form-label">Material (Serializado)</label>
                <select class="form-select" wire:model="itemInesperadoMaterialId">
                  <option value="">Selecione...</option>
                  @foreach ($this->materiaisSerializadosParaItemInesperado as $material)
                    <option value="{{ $material->id }}">{{ $material->codigo }} — {{ $material->descricao }}</option>
                  @endforeach
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Serial encontrado</label>
                <input type="text" class="form-control" wire:model="itemInesperadoSerial">
              </div>
              <div class="mb-3">
                <label class="form-label">Data da contagem</label>
                <input type="date" class="form-control" wire:model="itemInesperadoData" max="{{ now()->toDateString() }}">
              </div>
              <div class="mb-3">
                <label class="form-label">Observação (opcional)</label>
                <textarea class="form-control" wire:model="itemInesperadoObservacao" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalItemInesperado">Cancelar</button>
              <button type="button" class="btn btn-primary" wire:click="confirmarItemInesperado">Registrar</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Modal: Aprovar Ajuste --}}
    @if ($modalAjusteItemId)
      <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Aprovar Ajuste de Estoque</h5>
              <button type="button" class="btn-close" wire:click="fecharModalAjuste"></button>
            </div>
            <div class="modal-body">
              @error('ajusteGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <p class="text-muted small">Aprovar gera uma movimentação formal no estoque (Entrada ou Saída) correspondente à divergência encontrada. Esta ação não pode ser desfeita.</p>
              <div class="mb-3">
                <label class="form-label">Justificativa</label>
                <textarea class="form-control" wire:model="ajusteJustificativa" rows="3" placeholder="Explique a causa provável da divergência"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalAjuste">Cancelar</button>
              <button type="button" class="btn btn-success" wire:click="confirmarAjuste">Aprovar Ajuste</button>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Modal: Cancelar Inventário --}}
    @if ($modalCancelarInventarioAberto)
      <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Cancelar Inventário</h5>
              <button type="button" class="btn-close" wire:click="fecharModalCancelarInventario"></button>
            </div>
            <div class="modal-body">
              @error('cancelarInventarioGeral') <div class="alert alert-danger">{{ $message }}</div> @enderror
              <p class="text-muted small">Cancelar não apaga o histórico já registrado, não gera Ajuste e não altera nenhum saldo.</p>
              <div class="mb-3">
                <label class="form-label">Motivo do cancelamento</label>
                <textarea class="form-control" wire:model="cancelarInventarioMotivo" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" wire:click="fecharModalCancelarInventario">Voltar</button>
              <button type="button" class="btn btn-danger" wire:click="confirmarCancelarInventario">Cancelar Inventário</button>
            </div>
          </div>
        </div>
      </div>
    @endif
  @endif
</div>
