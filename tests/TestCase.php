<?php

namespace Tests;

use App\Models\Perfil;
use App\Models\User;
use App\Models\Work;
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
     */
    protected function vincularObra(Work $obra, User $user, string $slugPadrao): Perfil
    {
        $perfil = Perfil::porSlugPadrao($obra->tenant, $slugPadrao);

        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);

        return $perfil;
    }
}
