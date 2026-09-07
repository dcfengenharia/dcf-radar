<?php

namespace App\Enums;

use App\Models\AplicacaoMaterialEstoque;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\Material;
use App\Models\PedidoCompra;
use App\Models\Restricao;

/**
 * Ciclo 23, Etapa 23.1 — allowlist FECHADA de tipos de entidade que uma
 * lição pode referenciar. Nunca aceitar um `entidade_tipo` cru vindo do
 * request sem passar por este enum primeiro (`tryFrom()` retorna `null`
 * pra qualquer valor fora daqui).
 *
 * Cobertura deliberadamente parcial nesta etapa (Seção 14 do pedido:
 * "não implementar vínculo com todas automaticamente se isso inflar o
 * escopo") — Pedido/GRD/Industrialização ficam pra 23.2+, adicionando
 * só um `case` novo aqui, sem migration.
 *
 * Ciclo 23, Etapa 23.3 — `PedidoCompra`/`AplicacaoMaterialEstoque`
 * adicionados (aprovado explicitamente pelo usuário) pra servir de
 * origem real na conversão de candidatos B/C — nenhum outro tipo novo
 * (GRD/Industrialização ficam fora, candidatas rejeitadas/adiadas desta
 * etapa, ver relatório final).
 */
enum TipoEntidadeVinculoLicao: string
{
    case Atividade = 'atividade';
    case Restricao = 'restricao';
    case DocumentoEngenharia = 'documento_engenharia';
    case Material = 'material';
    case Fornecedor = 'fornecedor';
    case Pacote = 'pacote';
    case PedidoCompra = 'pedido_compra';
    case AplicacaoMaterialEstoque = 'aplicacao_material_estoque';

    public function label(): string
    {
        return match ($this) {
            self::Atividade => 'Atividade',
            self::Restricao => 'Restrição',
            self::DocumentoEngenharia => 'Documento de Engenharia',
            self::Material => 'Material',
            self::Fornecedor => 'Fornecedor',
            self::Pacote => 'Pacote de Compra',
            self::PedidoCompra => 'Pedido de Compra',
            self::AplicacaoMaterialEstoque => 'Aplicação de Material em Estoque',
        };
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Atividade => Atividade::class,
            self::Restricao => Restricao::class,
            self::DocumentoEngenharia => DocumentoEngenharia::class,
            self::Material => Material::class,
            self::Fornecedor => Fornecedor::class,
            self::Pacote => ItemSuprimento::class,
            self::PedidoCompra => PedidoCompra::class,
            self::AplicacaoMaterialEstoque => AplicacaoMaterialEstoque::class,
        };
    }

    /**
     * Ciclo 23, Etapa 23.2 — slug do catálogo de funcionalidades que
     * governa a VISUALIZAÇÃO desta entidade na sua tela de origem real
     * (confirmado por leitura direta de `CatalogoFuncionalidades`, nunca
     * presumido) — usado tanto pra decidir se um "link vivo" pode ser
     * exibido (Seção 13) quanto pra revalidar, no servidor, que o usuário
     * que dispara a captura contextual (Seção 38) realmente enxerga esse
     * tipo de entidade, nunca confiando só no fato de ter chegado com um
     * ID na URL.
     */
    public function funcionalidadeParaAcesso(): string
    {
        return match ($this) {
            self::Atividade => 'restricoes.lookahead',
            self::Restricao => 'restricoes.quadro',
            self::DocumentoEngenharia => 'engenharia.pacotes',
            self::Material => 'estoque.movimentacao',
            self::Fornecedor => 'cadastros.fornecedores',
            self::Pacote => 'suprimentos.mapa',
            self::PedidoCompra => 'suprimentos.mapa',
            self::AplicacaoMaterialEstoque => 'estoque.conciliacao',
        };
    }

    /**
     * ESCOPO_OBRA | ESCOPO_TENANT — espelha `CatalogoFuncionalidades`
     * (nunca redefinido aqui, só referenciado) porque a tela de origem de
     * DocumentoEngenharia/Fornecedor é tenant-wide (sem obra ativa
     * obrigatória), mesmo cada LINHA dessas entidades tendo uma obra bem
     * definida — checar acesso exige saber qual convenção usar.
     */
    public function escopoPagina(): string
    {
        return match ($this) {
            self::Atividade, self::Restricao, self::Material, self::Pacote,
            self::PedidoCompra, self::AplicacaoMaterialEstoque => \App\Support\CatalogoFuncionalidades::ESCOPO_OBRA,
            self::DocumentoEngenharia, self::Fornecedor => \App\Support\CatalogoFuncionalidades::ESCOPO_TENANT,
        };
    }

    /**
     * Ciclo 23, Etapa 23.2 (Seção 14-16) — só `Material` não tem
     * `obra_id` próprio (catálogo tenant-wide, Ciclo 20.1). Todos os
     * demais têm uma obra determinável de forma inequívoca (direta ou
     * via `VinculoLicaoResolver::obraIdDaEntidade()`), nunca uma obra
     * "forçada"/inventada. `PedidoCompra`/`AplicacaoMaterialEstoque`
     * (23.3) têm `obra_id` próprio e direto — confirmado por leitura
     * fresh dos dois models, nenhuma resolução indireta necessária.
     */
    public function obraDeterministica(): bool
    {
        return $this !== self::Material;
    }
}
