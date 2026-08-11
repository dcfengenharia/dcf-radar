<?php

namespace Tests\Unit;

use App\Support\HealthCheck\PlanoAcao\UidExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Fase 4.1, Parte G — extração de uid precisa funcionar corretamente nas
 * formas reais de HealthCheckFinding::$atividades (confirmadas lendo o
 * código das regras, não presumidas): plana, agrupada por grupo
 * (STRUCT-004/005), e par (LOGIC-005/009/010).
 */
class UidExtractorTest extends TestCase
{
    public function test_extrai_de_estrutura_plana(): void
    {
        $atividades = [
            ['uid' => '101', 'codigo' => '1.1', 'nome' => 'A'],
            ['uid' => '102', 'codigo' => '1.2', 'nome' => 'B'],
        ];

        $this->assertEqualsCanonicalizing(['101', '102'], UidExtractor::extrair($atividades));
    }

    public function test_extrai_de_estrutura_agrupada_struct005(): void
    {
        $atividades = [[
            'ciclo_id' => 1,
            'atividades' => [
                ['uid' => '201', 'codigo' => '2.1', 'nome' => 'C'],
                ['uid' => '202', 'codigo' => '2.2', 'nome' => 'D'],
            ],
            'relacoes' => [['de' => '201', 'para' => '202']],
        ]];

        $this->assertEqualsCanonicalizing(['201', '202'], UidExtractor::extrair($atividades));
    }

    public function test_extrai_de_estrutura_agrupada_struct004_com_metadados_numericos(): void
    {
        $atividades = [[
            'componente_id' => 1,
            'quantidade_atividades' => 2,
            'quantidade_relacoes' => 1,
            'atividades' => [
                ['uid' => '301', 'codigo' => '3.1', 'nome' => 'E'],
                ['uid' => '302', 'codigo' => '3.2', 'nome' => 'F'],
            ],
        ]];

        $this->assertEqualsCanonicalizing(['301', '302'], UidExtractor::extrair($atividades));
    }

    public function test_extrai_de_estrutura_par_predecessora_sucessora_logic005(): void
    {
        $atividades = [[
            'predecessora' => ['uid' => '401', 'codigo' => '4.1', 'nome' => 'G'],
            'sucessora' => ['uid' => '402', 'codigo' => '4.2', 'nome' => 'H'],
            'vinculos' => [
                ['tipo' => 'FS', 'tipo_codigo_original' => 1, 'link_lag' => 0, 'lag_format' => 7],
                ['tipo' => 'FS', 'tipo_codigo_original' => 1, 'link_lag' => 5, 'lag_format' => 7],
            ],
        ]];

        $this->assertEqualsCanonicalizing(['401', '402'], UidExtractor::extrair($atividades));
    }

    public function test_extrai_de_estrutura_par_com_listas_de_predecessoras_logic010(): void
    {
        $atividades = [[
            'sucessora' => ['uid' => '501', 'codigo' => '5.1', 'nome' => 'I'],
            'predecessoras_inativas' => [
                ['uid' => '502', 'codigo' => '5.2', 'nome' => 'J'],
            ],
            'predecessoras_ativas' => [
                ['uid' => '503', 'codigo' => '5.3', 'nome' => 'K'],
            ],
        ]];

        $this->assertEqualsCanonicalizing(['501', '502', '503'], UidExtractor::extrair($atividades));
    }

    public function test_multiplos_grupos_na_mesma_regra_extrai_uniao(): void
    {
        // Simula 2 findings distintos da mesma regra (2 ciclos separados) —
        // o extractor opera por finding individual, mas confirma que 2
        // chamadas sucessivas não misturam nem perdem uids.
        $grupo1 = [['ciclo_id' => 1, 'atividades' => [['uid' => '601'], ['uid' => '602']], 'relacoes' => []]];
        $grupo2 = [['ciclo_id' => 2, 'atividades' => [['uid' => '701'], ['uid' => '702']], 'relacoes' => []]];

        $this->assertEqualsCanonicalizing(['601', '602'], UidExtractor::extrair($grupo1));
        $this->assertEqualsCanonicalizing(['701', '702'], UidExtractor::extrair($grupo2));
    }

    public function test_uids_repetidos_sao_deduplicados(): void
    {
        $atividades = [
            ['uid' => '801', 'codigo' => '8.1', 'nome' => 'L'],
            ['uid' => '801', 'codigo' => '8.1', 'nome' => 'L'], // mesma atividade aparecendo 2x
        ];

        $this->assertSame(['801'], UidExtractor::extrair($atividades));
    }

    public function test_uid_repetido_dentro_de_estrutura_aninhada_e_deduplicado(): void
    {
        // Caso de borda: o mesmo uid aparece tanto como 'predecessora' quanto
        // dentro de outra lista (não deveria acontecer na prática, mas o
        // extrator não pode duplicar mesmo assim).
        $atividades = [[
            'sucessora' => ['uid' => '901'],
            'predecessoras_ativas' => [['uid' => '901']],
        ]];

        $this->assertSame(['901'], UidExtractor::extrair($atividades));
    }

    public function test_conjunto_vazio_retorna_array_vazio(): void
    {
        $this->assertSame([], UidExtractor::extrair([]));
    }

    public function test_estrutura_sem_nenhuma_chave_uid_retorna_array_vazio(): void
    {
        $atividades = [
            ['ciclo_id' => 1, 'relacoes' => [['de' => 'x', 'para' => 'y']]],
        ];

        $this->assertSame([], UidExtractor::extrair($atividades));
    }
}
