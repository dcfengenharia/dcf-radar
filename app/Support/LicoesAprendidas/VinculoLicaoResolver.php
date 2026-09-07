<?php

namespace App\Support\LicoesAprendidas;

use App\Enums\TipoEntidadeVinculoLicao;
use App\Models\AplicacaoMaterialEstoque;
use App\Models\Atividade;
use App\Models\PedidoCompra;
use App\Models\Restricao;
use App\Models\User;
use App\Support\CatalogoFuncionalidades;
use Illuminate\Database\Eloquent\Model;

/**
 * Ciclo 23, Etapa 23.1 — único ponto que resolve um vínculo (tipo+id)
 * pra um Model real, congela o snapshot de título, e responde "a quem
 * pertence esta entidade" pra a UI nunca oferecer um link vivo pra
 * quem não tem acesso à obra dona dela (Seção 26 do pedido — fecha o
 * risco de IDOR via vínculo).
 *
 * Nunca instancia a classe via `TipoEntidadeVinculoLicao::modelClass()`
 * sem passar por `find()` — isso já garante escopo de tenant de graça
 * (toda entidade candidata usa `BelongsToTenant`), mas quem chama este
 * serviço AINDA precisa confirmar que o resultado não é `null` (id de
 * outro tenant/inexistente) antes de criar o vínculo.
 */
class VinculoLicaoResolver
{
    public static function resolver(TipoEntidadeVinculoLicao $tipo, string $entidadeId): ?Model
    {
        return $tipo->modelClass()::find($entidadeId);
    }

    public static function tituloParaSnapshot(TipoEntidadeVinculoLicao $tipo, Model $entidade): string
    {
        return match ($tipo) {
            TipoEntidadeVinculoLicao::Atividade => self::tituloAtividade($entidade),
            TipoEntidadeVinculoLicao::Restricao => self::tituloRestricao($entidade),
            TipoEntidadeVinculoLicao::DocumentoEngenharia => trim("{$entidade->codigo} - {$entidade->descricao}", ' -'),
            TipoEntidadeVinculoLicao::Material => trim("{$entidade->codigo} - {$entidade->descricao}", ' -'),
            TipoEntidadeVinculoLicao::Fornecedor => (string) $entidade->nome,
            TipoEntidadeVinculoLicao::Pacote => (string) $entidade->nome,
            TipoEntidadeVinculoLicao::PedidoCompra => self::tituloPedidoCompra($entidade),
            TipoEntidadeVinculoLicao::AplicacaoMaterialEstoque => self::tituloAplicacaoMaterialEstoque($entidade),
        };
    }

    /**
     * Obra dona da entidade — `null` quando a entidade não tem obra
     * própria (ex.: Material, catálogo tenant-wide desde o Ciclo 20.1) —
     * nesse caso não há "acesso à obra" a checar, o vínculo é sempre
     * exibível a qualquer usuário do tenant.
     */
    public static function obraIdDaEntidade(TipoEntidadeVinculoLicao $tipo, Model $entidade): ?string
    {
        return match ($tipo) {
            TipoEntidadeVinculoLicao::Atividade,
            TipoEntidadeVinculoLicao::DocumentoEngenharia,
            TipoEntidadeVinculoLicao::Fornecedor,
            TipoEntidadeVinculoLicao::Pacote => $entidade->obra_id,
            TipoEntidadeVinculoLicao::PedidoCompra,
            TipoEntidadeVinculoLicao::AplicacaoMaterialEstoque => $entidade->obra_id,
            TipoEntidadeVinculoLicao::Restricao => $entidade->atividade?->obra_id,
            TipoEntidadeVinculoLicao::Material => null,
        };
    }

    /**
     * Ciclo 23, Etapa 23.2 (Seção 13) — "link vivo somente se a entidade
     * ainda existir e o usuário atual possuir autorização independente
     * para acessá-la. Não conceder acesso por causa da lição." Nunca
     * confunde "pode ver a LIÇÃO" com "pode ver a ENTIDADE vinculada" —
     * usa exatamente a MESMA checagem que a tela de origem daquele tipo
     * de entidade já usa pra decidir se o item aparece nela.
     */
    public static function usuarioTemAcessoIndependente(User $user, TipoEntidadeVinculoLicao $tipo, ?string $obraId): bool
    {
        $slug = $tipo->funcionalidadeParaAcesso();

        if ($tipo->escopoPagina() === CatalogoFuncionalidades::ESCOPO_TENANT) {
            return $user->temPermissaoEmAlgumaObraDoTenant($slug, 'ver');
        }

        return $obraId !== null && $user->temPermissaoNaObra($obraId, $slug, 'ver');
    }

    /**
     * Ciclo 23, Etapa 23.2 (Seção 13/30) — deep-link pra tela de ORIGEM
     * da entidade, nunca um permalink por registro (nenhuma das 5 telas
     * capturadas nesta etapa tem rota de detalhe individual — mesmo
     * idioma já usado por `SituacaoGerencial::$deepLink`, Ciclo 21: link
     * pra LISTAGEM, nunca URL montada à mão). Rotas `radar.*` vivem
     * dentro do grupo `obra.context` (resolvem a obra pela sessão, não
     * por parâmetro) — clicar leva pra tela certa, mas só realça o
     * registro certo se a obra ativa em sessão já for a mesma; limitação
     * já aceita explicitamente em outro ponto do projeto (Health Check,
     * Fase 4.3) pela mesma razão estrutural, não reinventada aqui.
     *
     * @return array{rota: string, parametros: array<string, mixed>}
     */
    public static function deepLinkParaEntidade(TipoEntidadeVinculoLicao $tipo): array
    {
        return match ($tipo) {
            TipoEntidadeVinculoLicao::Atividade => ['rota' => 'radar.lookahead', 'parametros' => []],
            TipoEntidadeVinculoLicao::Restricao => ['rota' => 'radar.restricoes', 'parametros' => []],
            TipoEntidadeVinculoLicao::DocumentoEngenharia => ['rota' => 'engenharia.pacotes', 'parametros' => []],
            TipoEntidadeVinculoLicao::Material => ['rota' => 'radar.estoque', 'parametros' => []],
            TipoEntidadeVinculoLicao::Fornecedor => ['rota' => 'cadastros.fornecedores', 'parametros' => []],
            TipoEntidadeVinculoLicao::Pacote => ['rota' => 'radar.suprimentos', 'parametros' => []],
            TipoEntidadeVinculoLicao::PedidoCompra => ['rota' => 'radar.suprimentos', 'parametros' => []],
            TipoEntidadeVinculoLicao::AplicacaoMaterialEstoque => ['rota' => 'radar.estoque', 'parametros' => []],
        };
    }

    private static function tituloAtividade(Atividade $atividade): string
    {
        return $atividade->codigo_cronograma
            ? "{$atividade->codigo_cronograma} - {$atividade->nome}"
            : (string) $atividade->nome;
    }

    private static function tituloRestricao(Restricao $restricao): string
    {
        return 'Restrição: '.str($restricao->descricao)->limit(80);
    }

    /** Ciclo 23, Etapa 23.3 — `fornecedor_nome_snapshot` primeiro (já congelado na emissão, nunca lazy-load do cadastro vivo). */
    private static function tituloPedidoCompra(PedidoCompra $pedido): string
    {
        $fornecedor = $pedido->fornecedor_nome_snapshot ?: $pedido->fornecedor?->nome ?: 'Fornecedor';

        return "Pedido #{$pedido->numero} - {$fornecedor}";
    }

    private static function tituloAplicacaoMaterialEstoque(AplicacaoMaterialEstoque $aplicacao): string
    {
        $data = $aplicacao->aplicado_em?->format('d/m/Y') ?? '—';

        return "Aplicação de material em {$data}";
    }
}
