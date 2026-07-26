<?php

namespace App\Support\Onboarding;

use App\Models\CategoriaRestricao;
use App\Models\Client;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Work;
use App\Support\ObraContext;

/**
 * Ponto único de manutenção do passo a passo de configuração inicial.
 * Ao introduzir um novo cadastro obrigatório no sistema, adicione o passo
 * aqui — em passosTenant() ou passosObra() conforme o escopo.
 */
class OnboardingChecklist
{
    public static function passosTenant(): array
    {
        return [
            new PassoConfiguracao(
                chave: 'cliente_cadastrado',
                titulo: 'Cadastrar 1º Cliente',
                descricao: 'Cadastre o primeiro cliente para o qual você vai gerenciar obras.',
                obrigatorio: true,
                rotaAcao: 'cadastros.clientes.index',
                rotuloAcao: 'Cadastrar Cliente',
                verificar: fn () => Client::query()->exists(),
            ),
            new PassoConfiguracao(
                chave: 'obra_cadastrada',
                titulo: 'Cadastrar 1ª Obra',
                descricao: 'Crie a primeira obra para começar a gerenciar o canteiro de obras da sua empresa.',
                obrigatorio: true,
                rotaAcao: 'cadastros.obras.index',
                rotuloAcao: 'Cadastrar Obra',
                verificar: fn () => Work::query()->exists(),
            ),
            new PassoConfiguracao(
                chave: 'categoria_restricao_cadastrada',
                titulo: 'Configurar Tipos de Restrição',
                descricao: 'Opcional: classifique suas restrições pelos 5 pilares do Lean Construction.',
                obrigatorio: false,
                rotaAcao: 'cadastros.categorias-restricao',
                rotuloAcao: 'Configurar',
                verificar: fn () => CategoriaRestricao::query()->exists(),
            ),
        ];
    }

    public static function passosObra(Work $obra): array
    {
        return [
            new PassoConfiguracao(
                chave: 'atividades_cadastradas',
                titulo: 'Cadastrar Atividades',
                descricao: 'Importe o cronograma (.xml) ou cadastre atividades manualmente para esta obra.',
                obrigatorio: true,
                rotaAcao: 'radar.cronograma',
                rotuloAcao: 'Importar Cronograma',
                verificar: fn () => $obra->atividades()->exists(),
            ),
            new PassoConfiguracao(
                chave: 'restricao_cadastrada',
                titulo: 'Cadastrar 1ª Restrição',
                descricao: 'Registre a primeira restrição no Quadro de Restrições — é o coração do Last Planner System.',
                obrigatorio: false,
                rotaAcao: 'radar.restricoes',
                rotuloAcao: 'Ir para o Quadro de Restrições',
                verificar: fn () => Restricao::whereHas('atividade', fn ($q) => $q->where('obra_id', $obra->id))->exists(),
            ),
            new PassoConfiguracao(
                chave: 'item_prontidao_cadastrado',
                titulo: 'Configurar Itens de Prontidão',
                descricao: 'Opcional: defina um checklist adicional de prontidão para esta obra.',
                obrigatorio: false,
                rotaAcao: 'cadastros.itens-prontidao',
                rotuloAcao: 'Configurar',
                verificar: fn () => ItemProntidao::where('obra_id', $obra->id)->exists(),
            ),
        ];
    }

    /**
     * @param  PassoConfiguracao[]  $passos
     * @return PassoConfiguracao[]
     */
    public static function pendentes(array $passos): array
    {
        return array_values(array_filter($passos, fn (PassoConfiguracao $passo) => ! $passo->estaConcluido()));
    }

    /**
     * @param  PassoConfiguracao[]  $passos
     * @return PassoConfiguracao[]
     */
    public static function pendentesObrigatorios(array $passos): array
    {
        return array_values(array_filter(
            self::pendentes($passos),
            fn (PassoConfiguracao $passo) => $passo->obrigatorio
        ));
    }

    /**
     * Pendências obrigatórias do tenant atual + da obra selecionada (se houver).
     * Usado pelo botão do menu lateral e pelo aviso da navbar.
     *
     * @return PassoConfiguracao[]
     */
    public static function pendenciasAtuais(): array
    {
        if (! auth()->check()) {
            return [];
        }

        $pendenciasTenant = self::pendentesObrigatorios(self::passosTenant());

        $obra = ObraContext::current();
        $pendenciasObra = $obra ? self::pendentesObrigatorios(self::passosObra($obra)) : [];

        return array_merge($pendenciasTenant, $pendenciasObra);
    }
}
