<?php

namespace Tests;

use App\Enums\Papel;
use App\Models\Perfil;
use App\Models\User;
use App\Models\Work;
use App\Support\AtribuicaoPerfilObra;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Vincula $user à $obra com o perfil padrão equivalente ao Papel
     * legado indicado por $slugPadrao ('admin'|'gerente_planejamento'|
     * 'engenheiro'|'encarregado'|'cliente_leitura') — substitui, em
     * todos os testes, o antigo `$obra->users()->attach($user->id,
     * ['papel' => Papel::X->value])`. O perfil padrão já existe (criado
     * automaticamente quando o tenant da obra foi criado, via
     * Tenant::booted() -> Perfil::seedPadrao()).
     *
     * Fase 2B — também grava na nova pivot multiperfil
     * (`obra_user_perfil`, via `AtribuicaoPerfilObra`), nunca só no
     * espelho legado `obra_user.perfil_id`. Não é estritamente
     * necessário pro resolver funcionar (`HasObraPapel` cai pro espelho
     * legado quando a nova pivot está vazia — união, nunca
     * substituição), mas mantém este helper coerente com a regra da
     * Seção 28 do pedido ("todo método legado que atribui 1 Perfil deve
     * resultar em... exatamente o Perfil esperado na nova pivot") e
     * permite que testes específicos de multiperfil verifiquem a nova
     * tabela diretamente sem precisar de um segundo helper.
     */
    protected function vincularObra(Work $obra, User $user, string $slugPadrao): Perfil
    {
        $perfil = Perfil::porSlugPadrao($obra->tenant, $slugPadrao);

        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($obra, $user->id, $perfil->id);

        return $perfil;
    }

    /**
     * Fase 2B, Seção 42 — atribui um SEGUNDO (ou N-ésimo) perfil ao par
     * (obra, usuário) SEM substituir o(s) já existente(s), diferente de
     * `vincularObra()` (que sempre representa "1 perfil", substituindo).
     * Só grava na nova pivot — nunca mexe no espelho legado
     * `obra_user.perfil_id` (ele continua representando o "perfil
     * primário" pra exibição/compatibilidade, ver
     * `HasObraPapel::perfilNaObra()`). Usa `firstOrCreate()` — nunca
     * duplica se chamado 2x para o mesmo perfil.
     */
    protected function adicionarPerfilExtra(Work $obra, User $user, string $slugPadrao): Perfil
    {
        $perfil = Perfil::porSlugPadrao($obra->tenant, $slugPadrao);

        \App\Models\ObraUserPerfil::firstOrCreate([
            'work_id' => $obra->id,
            'user_id' => $user->id,
            'perfil_id' => $perfil->id,
        ], [
            'tenant_id' => $obra->tenant_id,
        ]);

        return $perfil;
    }

    /**
     * Fase 2E.CORREÇÃO — cria um ator NOVO e DEDICADO, vinculado como
     * Admin na obra informada. Usado por testes que precisam de um
     * autor legítimo pra Actions com defesa em profundidade (ex.:
     * `AlterarLiberacaoRevisaoDocumento`, gated por
     * `engenharia.pacotes|liberar_para_construcao`, tier Admin) só pra
     * preparar estado de fixture ("documento já liberado") — sem
     * promover o ator sob teste (`$this->user`, frequentemente
     * Encarregado/Engenheiro/GerentePlanejamento nesses arquivos) a um
     * tier que contaminaria as próprias asserções de permissão daquele
     * teste. Sempre um usuário NOVO (nunca reaproveita `$this->user`)
     * — evita colidir com o índice único legado de `obra_user` quando o
     * mesmo teste já vinculou `$this->user` à mesma obra com outro
     * Papel.
     */
    protected function usuarioComAutoridadeAdmin(Work $obra): User
    {
        $usuario = User::factory()->create(['tenant_id' => $obra->tenant_id]);
        $this->vincularObra($obra, $usuario, Papel::Admin->value);

        return $usuario;
    }
}
