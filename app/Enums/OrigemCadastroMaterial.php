<?php

namespace App\Enums;

/**
 * Melhoria "Posto Operacional" — proveniência do CADASTRO do Material
 * Mestre em si, nunca confundir com `App\Enums\OrigemNecessidadeMaterialAtividade`
 * (que é a proveniência da NECESSIDADE de uma atividade — duas perguntas
 * diferentes: "de onde veio este item de catálogo?" vs "de onde veio a
 * declaração de que esta atividade precisa dele?"). Mesmo padrão
 * minimalista já usado por `App\Enums\OrigemItemTakeOff`
 * (Importado|Manual): coluna string simples no próprio registro, nunca
 * uma tabela de auditoria genérica.
 *
 * `Catalogo` = cadastrado diretamente na tela de Estoque
 * (`⚡estoque.blade.php::salvarMaterial()`) — valor DEFAULT no banco,
 * cobre também todo o histórico anterior a esta melhoria (nenhum
 * backfill necessário).
 * `PlanoSemanal` = criado inline durante o preenchimento do popup
 * operacional do Plano Semanal, sem sair da atividade — NUNCA implica
 * que o material veio de Engenharia/TakeOff (Material nunca é criado
 * automaticamente por importação/associação de TakeOff, só associado a
 * um Material já existente via `App\Actions\Estoque\AssociarMaterialAoItemTakeOff`).
 */
enum OrigemCadastroMaterial: string
{
    case Catalogo = 'catalogo';
    case PlanoSemanal = 'plano_semanal';

    public function label(): string
    {
        return match ($this) {
            self::Catalogo => 'Catálogo',
            self::PlanoSemanal => 'Plano Semanal — reunião de programação',
        };
    }
}
