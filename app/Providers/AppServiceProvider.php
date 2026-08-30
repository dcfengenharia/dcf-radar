<?php

namespace App\Providers;

use App\Imports\Contracts\ImportadorCronograma;
use App\Imports\MsProjectImporter;
use App\Models\Atividade;
use App\Models\AplicacaoMaterialEstoque;
use App\Models\TransferenciaEstoque;
use App\Models\DestinacaoPlanejadaMaterial;
use App\Models\EntregaProdutoIndustrializado;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Grd;
use App\Models\GrdAceiteEntrega;
use App\Models\ItemTakeOff;
use App\Models\ItemSuprimento;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemIndustrializacao;
use App\Models\PedidoCompra;
use App\Models\ProducaoIndustrializada;
use App\Models\ProdutoIndustrializado;
use App\Models\ProdutoIndustrializadoConsumo;
use App\Models\RecebimentoPedido;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoPlanejamento;
use App\Models\RemessaIndustrializacao;
use App\Models\ReservaEstoque;
use App\Models\Restricao;
use App\Models\RevisaoLiberacao;
use App\Observers\AtividadeObserver;
use App\Observers\AplicacaoMaterialEstoqueObserver;
use App\Observers\TransferenciaEstoqueObserver;
use App\Models\InventarioEstoque;
use App\Observers\InventarioEstoqueObserver;
use App\Models\InventarioItem;
use App\Observers\InventarioItemObserver;
use App\Models\ContagemInventario;
use App\Observers\ContagemInventarioObserver;
use App\Models\InventarioAjuste;
use App\Observers\InventarioAjusteObserver;
use App\Observers\DestinacaoPlanejadaMaterialObserver;
use App\Observers\EntregaProdutoIndustrializadoObserver;
use App\Observers\DocumentoEngenhariaRevisaoObserver;
use App\Observers\GrdAceiteEntregaObserver;
use App\Observers\GrdObserver;
use App\Observers\ItemSuprimentoObserver;
use App\Observers\ItemTakeOffObserver;
use App\Observers\ListaEngenhariaObserver;
use App\Observers\LocalEstoqueObserver;
use App\Observers\MaterialObserver;
use App\Observers\MovimentacaoEstoqueObserver;
use App\Observers\OrdemIndustrializacaoObserver;
use App\Observers\PedidoCompraObserver;
use App\Observers\ProducaoIndustrializadaObserver;
use App\Observers\ProdutoIndustrializadoConsumoObserver;
use App\Observers\ProdutoIndustrializadoObserver;
use App\Observers\RecebimentoPedidoObserver;
use App\Observers\RequisicaoCompraObserver;
use App\Observers\RequisicaoPlanejamentoObserver;
use App\Observers\RemessaIndustrializacaoObserver;
use App\Observers\ReservaEstoqueObserver;
use App\Observers\RestricaoObserver;
use App\Observers\RevisaoLiberacaoObserver;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ImportadorCronograma::class, MsProjectImporter::class);
    }

    public function boot(): void
    {
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(! app()->isProduction());

        Atividade::observe(AtividadeObserver::class);
        Restricao::observe(RestricaoObserver::class);
        Grd::observe(GrdObserver::class);
        GrdAceiteEntrega::observe(GrdAceiteEntregaObserver::class);
        DocumentoEngenhariaRevisao::observe(DocumentoEngenhariaRevisaoObserver::class);
        RevisaoLiberacao::observe(RevisaoLiberacaoObserver::class);
        ListaEngenharia::observe(ListaEngenhariaObserver::class);
        ItemTakeOff::observe(ItemTakeOffObserver::class);
        RequisicaoPlanejamento::observe(RequisicaoPlanejamentoObserver::class);
        ItemSuprimento::observe(ItemSuprimentoObserver::class);
        RequisicaoCompra::observe(RequisicaoCompraObserver::class);
        PedidoCompra::observe(PedidoCompraObserver::class);
        RecebimentoPedido::observe(RecebimentoPedidoObserver::class);
        MovimentacaoEstoque::observe(MovimentacaoEstoqueObserver::class);
        Material::observe(MaterialObserver::class);
        LocalEstoque::observe(LocalEstoqueObserver::class);
        ReservaEstoque::observe(ReservaEstoqueObserver::class);
        DestinacaoPlanejadaMaterial::observe(DestinacaoPlanejadaMaterialObserver::class);
        OrdemIndustrializacao::observe(OrdemIndustrializacaoObserver::class);
        ProdutoIndustrializado::observe(ProdutoIndustrializadoObserver::class);
        RemessaIndustrializacao::observe(RemessaIndustrializacaoObserver::class);
        ProdutoIndustrializadoConsumo::observe(ProdutoIndustrializadoConsumoObserver::class);
        ProducaoIndustrializada::observe(ProducaoIndustrializadaObserver::class);
        EntregaProdutoIndustrializado::observe(EntregaProdutoIndustrializadoObserver::class);
        AplicacaoMaterialEstoque::observe(AplicacaoMaterialEstoqueObserver::class);
        TransferenciaEstoque::observe(TransferenciaEstoqueObserver::class);
        InventarioEstoque::observe(InventarioEstoqueObserver::class);
        InventarioItem::observe(InventarioItemObserver::class);
        ContagemInventario::observe(ContagemInventarioObserver::class);
        InventarioAjuste::observe(InventarioAjusteObserver::class);

        // Marca a sessão pra <x-onboarding-popup /> mostrar o popup de boas-vindas
        // no próximo carregamento de página, se ainda houver cadastro obrigatório
        // pendente. Cobre login normal e Auth::login() pós-registro (Fortify e
        // RegisteredUserController disparam este mesmo evento).
        Event::listen(Login::class, function (): void {
            session(['mostrar_popup_onboarding' => true]);
        });
    }
}
