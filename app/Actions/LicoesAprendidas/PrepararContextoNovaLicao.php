<?php

namespace App\Actions\LicoesAprendidas;

use App\DTOs\LicoesAprendidas\ContextoNovaLicao;
use App\Enums\AreaFuncionalLicao;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Exceptions\VinculoLicaoInvalidoException;
use App\Models\User;
use App\Models\Work;
use App\Support\LicoesAprendidas\VinculoLicaoResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Ciclo 23, Etapa 23.2 — ÚNICO ponto que monta o contexto de uma
 * captura contextual ("Registrar como lição aprendida" a partir de um
 * objeto operacional). Nunca cria nada — só LEITURA + composição de
 * sugestões (Seção 20/32/33: "não criar cinco formulários separados",
 * "montagem do contexto centralizada num serviço, nunca lógica
 * espalhada em Blades").
 *
 * Segurança (Seção 38): revalida, aqui — nunca só na UI de origem — que
 * o usuário tem acesso INDEPENDENTE à entidade de origem
 * (`VinculoLicaoResolver::usuarioTemAcessoIndependente()`), nunca
 * confiando em "o ID chegou na URL" como prova de acesso.
 */
class PrepararContextoNovaLicao
{
    public function execute(
        User $usuario,
        TipoEntidadeVinculoLicao $tipo,
        string $entidadeId,
        ?Work $obraContextoFallback = null,
    ): ContextoNovaLicao {
        $entidade = VinculoLicaoResolver::resolver($tipo, $entidadeId);

        if (! $entidade) {
            throw new VinculoLicaoInvalidoException(
                'A entidade de origem não foi encontrada ou você não tem acesso a ela.'
            );
        }

        $obraId = VinculoLicaoResolver::obraIdDaEntidade($tipo, $entidade);
        $obraSugerida = $obraId ? Work::find($obraId) : null;

        // Seção 15/16: Material nunca tem obra própria — a checagem de
        // acesso usa a obra que o usuário estava vendo NA TELA DE
        // ORIGEM (passada explicitamente pelo chamador), nunca inferida.
        $obraParaChecagemDeAcesso = $tipo->obraDeterministica() ? $obraId : $obraContextoFallback?->id;

        if (! VinculoLicaoResolver::usuarioTemAcessoIndependente($usuario, $tipo, $obraParaChecagemDeAcesso)) {
            throw new VinculoLicaoInvalidoException(
                'Você não tem permissão para acessar esta entidade de origem.'
            );
        }

        $tituloSnapshot = VinculoLicaoResolver::tituloParaSnapshot($tipo, $entidade);

        return new ContextoNovaLicao(
            tipoOrigem: $tipo,
            entidadeOrigemId: $entidade->id,
            tituloOrigemSnapshot: $tituloSnapshot,
            obraSugerida: $obraSugerida,
            obraDeterministica: $tipo->obraDeterministica(),
            tituloSugerido: 'Lição relacionada a: '.$tituloSnapshot,
            disciplinaIdSugerida: $this->disciplinaSugerida($tipo, $entidade),
            areaSugerida: $this->areaSugerida($tipo),
            vinculosComplementares: $this->vinculosComplementares($tipo, $entidade),
        );
    }

    /**
     * Seção 17 — só sugere disciplina quando a entidade de origem (ou a
     * Atividade que ela referencia) já tem uma disciplina AUTORITATIVA
     * própria, nunca inferida por texto/heurística.
     */
    private function disciplinaSugerida(TipoEntidadeVinculoLicao $tipo, Model $entidade): ?string
    {
        return match ($tipo) {
            TipoEntidadeVinculoLicao::Atividade,
            TipoEntidadeVinculoLicao::DocumentoEngenharia => $entidade->disciplina_id,
            TipoEntidadeVinculoLicao::Restricao => $entidade->atividade?->disciplina_id,
            default => null,
        };
    }

    /** Seção 18 — sugestão inicial editável, nunca uma regra fixa. */
    private function areaSugerida(TipoEntidadeVinculoLicao $tipo): AreaFuncionalLicao
    {
        return match ($tipo) {
            TipoEntidadeVinculoLicao::Atividade,
            TipoEntidadeVinculoLicao::Restricao => AreaFuncionalLicao::Planejamento,
            TipoEntidadeVinculoLicao::DocumentoEngenharia => AreaFuncionalLicao::Engenharia,
            TipoEntidadeVinculoLicao::Material => AreaFuncionalLicao::Estoque,
            TipoEntidadeVinculoLicao::Fornecedor,
            TipoEntidadeVinculoLicao::Pacote => AreaFuncionalLicao::Suprimentos,
        };
    }

    /**
     * Seção 9/10 — só relações DETERMINÍSTICAS (FK real, cardinalidade
     * 1), nunca heurística nem expansão indiscriminada. Único caso real
     * encontrado no schema atual: uma Restrição sempre referencia (FK
     * singular) sua Atividade, e opcionalmente o Pacote de Compra que a
     * originou (`origem_suprimento_item_id`, Ciclo 19.7). Os demais 4
     * tipos de origem não têm nenhuma FK singular própria apontando pra
     * outro dos 6 tipos vinculáveis — nenhum vínculo complementar
     * inventado pra eles.
     *
     * @return array<int, array{tipo: TipoEntidadeVinculoLicao, id: string, titulo: string}>
     */
    private function vinculosComplementares(TipoEntidadeVinculoLicao $tipo, Model $entidade): array
    {
        if ($tipo !== TipoEntidadeVinculoLicao::Restricao) {
            return [];
        }

        $complementares = [];

        $atividade = $entidade->atividade;
        if ($atividade) {
            $complementares[] = [
                'tipo' => TipoEntidadeVinculoLicao::Atividade,
                'id' => $atividade->id,
                'titulo' => VinculoLicaoResolver::tituloParaSnapshot(TipoEntidadeVinculoLicao::Atividade, $atividade),
            ];
        }

        if ($entidade->origem_suprimento_item_id) {
            $pacote = $entidade->origemSuprimentoItem;
            if ($pacote) {
                $complementares[] = [
                    'tipo' => TipoEntidadeVinculoLicao::Pacote,
                    'id' => $pacote->id,
                    'titulo' => VinculoLicaoResolver::tituloParaSnapshot(TipoEntidadeVinculoLicao::Pacote, $pacote),
                ];
            }
        }

        return $complementares;
    }
}
