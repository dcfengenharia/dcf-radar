<?php

namespace Tests\Unit;

use App\Models\ItemTakeOff;
use App\Models\UnidadeMedida;
use App\Support\TakeOff\CurvaAbcTakeOff;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CurvaAbcTakeOffTest extends TestCase
{
    private function item(float $quantidade): ItemTakeOff
    {
        return new ItemTakeOff(['quantidade' => $quantidade]);
    }

    /**
     * Item com `unidadeMedida` "carregada" em memória via setRelation()
     * (sem tocar banco — este arquivo é Unit puro) — simula exatamente
     * o que `TakeOffConsolidado::itensVigentes()` entrega com
     * `->with('unidadeMedida')` de verdade.
     */
    private function itemComUnidade(float $quantidade, ?string $codigoUnidade): ItemTakeOff
    {
        $item = new ItemTakeOff(['quantidade' => $quantidade]);

        if ($codigoUnidade === null) {
            $item->setRelation('unidadeMedida', null);
            return $item;
        }

        $unidade = new UnidadeMedida(['codigo' => $codigoUnidade, 'nome' => $codigoUnidade]);
        $unidade->id = $codigoUnidade; // id sintético só pra servir de chave de agrupamento neste teste.
        $item->unidade_medida_id = $codigoUnidade;
        $item->setRelation('unidadeMedida', $unidade);

        return $item;
    }

    public function test_colecao_vazia_retorna_array_vazio(): void
    {
        $this->assertSame([], CurvaAbcTakeOff::calcular(new Collection()));
    }

    public function test_soma_total_zero_retorna_array_vazio(): void
    {
        $itens = new Collection([$this->item(0), $this->item(0)]);
        $this->assertSame([], CurvaAbcTakeOff::calcular($itens));
    }

    public function test_item_unico_fica_100_por_cento_classe_a(): void
    {
        $resultado = CurvaAbcTakeOff::calcular(new Collection([$this->item(50)]));

        $this->assertCount(1, $resultado);
        $this->assertSame(100.0, $resultado[0]['percentual_acumulado']);
        $this->assertSame('A', $resultado[0]['classe']);
    }

    public function test_ordena_por_quantidade_decrescente(): void
    {
        $itens = new Collection([$this->item(10), $this->item(90)]);
        $resultado = CurvaAbcTakeOff::calcular($itens);

        $this->assertSame(90.0, (float) $resultado[0]['item']->quantidade);
        $this->assertSame(10.0, (float) $resultado[1]['item']->quantidade);
    }

    public function test_classificacao_classica_80_95(): void
    {
        // Total 100: itens de 50/30/10/6/4 -> acumulado ANTES de cada um
        // é 0/50/80/90/96 -> A/A/B/B/C (o item de 10% cruza o limite de A
        // exatamente ao ser somado, então ele já é B; o de 4% cruza o de
        // B, já é C).
        $itens = new Collection([$this->item(50), $this->item(30), $this->item(10), $this->item(6), $this->item(4)]);
        $resultado = CurvaAbcTakeOff::calcular($itens);

        $this->assertSame(['A', 'A', 'B', 'B', 'C'], array_column($resultado, 'classe'));
        $this->assertSame(100.0, $resultado[4]['percentual_acumulado']);
    }

    public function test_maior_item_sozinho_e_sempre_classe_a_mesmo_dominando_o_total(): void
    {
        // Total 100: um único item responde por 85% sozinho -> classificado
        // pelo acumulado ANTES dele (0%, ainda não cruzou 80%) -> A. Nunca
        // vira C só porque a própria fatia já é maior que o limite.
        $itens = new Collection([$this->item(85), $this->item(15)]);
        $resultado = CurvaAbcTakeOff::calcular($itens);

        $this->assertSame('A', $resultado[0]['classe']);
        $this->assertSame(85.0, $resultado[0]['percentual_acumulado']);
        $this->assertSame('B', $resultado[1]['classe']);
    }

    /**
     * Teste P (seção 12/18 da correção 19.1) — a ABC NUNCA soma
     * quantidades de unidades incompatíveis (kg + un + m²) como se
     * fossem a mesma grandeza: `calcularAgrupadoPorUnidade()` separa
     * antes de classificar, cada grupo com seu próprio percentual/
     * classificação independente.
     */
    public function test_p_agrupado_por_unidade_nunca_mistura_grandezas_incompativeis(): void
    {
        $itens = new Collection([
            $this->itemComUnidade(100, 'KG'),
            $this->itemComUnidade(50, 'KG'),
            $this->itemComUnidade(10, 'UN'),
            $this->itemComUnidade(5, 'UN'),
        ]);

        $grupos = CurvaAbcTakeOff::calcularAgrupadoPorUnidade($itens);

        $this->assertCount(2, $grupos);

        // Maior grandeza de volume primeiro (KG: total 150 > UN: total 15).
        $this->assertSame('KG', $grupos[0]['unidade_label']);
        $this->assertSame(150.0, $grupos[0]['total_quantidade']);
        $this->assertCount(2, $grupos[0]['linhas']);
        // Dentro do grupo KG, o percentual é relativo só ao total de KG
        // (100/150 = 66,67%), nunca ao total geral (100/165).
        $this->assertEqualsWithDelta(66.67, $grupos[0]['linhas'][0]['percentual'], 0.01);

        $this->assertSame('UN', $grupos[1]['unidade_label']);
        $this->assertSame(15.0, $grupos[1]['total_quantidade']);
        $this->assertCount(2, $grupos[1]['linhas']);
        $this->assertEqualsWithDelta(66.67, $grupos[1]['linhas'][0]['percentual'], 0.01);
    }

    public function test_agrupado_por_unidade_trata_item_sem_unidade_como_grupo_proprio(): void
    {
        $itens = new Collection([
            $this->itemComUnidade(10, 'KG'),
            $this->itemComUnidade(20, null),
        ]);

        $grupos = CurvaAbcTakeOff::calcularAgrupadoPorUnidade($itens);

        $this->assertCount(2, $grupos);
        $semUnidade = collect($grupos)->firstWhere('unidade_label', 'Sem unidade');
        $this->assertNotNull($semUnidade);
        $this->assertNull($semUnidade['unidade_medida_id']);
        $this->assertSame(20.0, $semUnidade['total_quantidade']);
    }

    public function test_agrupado_por_unidade_colecao_vazia_retorna_array_vazio(): void
    {
        $this->assertSame([], CurvaAbcTakeOff::calcularAgrupadoPorUnidade(new Collection()));
    }

    public function test_agrupado_por_unidade_grupo_unico_classifica_normalmente(): void
    {
        $itens = new Collection([$this->itemComUnidade(80, 'M'), $this->itemComUnidade(20, 'M')]);
        $grupos = CurvaAbcTakeOff::calcularAgrupadoPorUnidade($itens);

        $this->assertCount(1, $grupos);
        $this->assertSame('A', $grupos[0]['linhas'][0]['classe']);
    }
}
