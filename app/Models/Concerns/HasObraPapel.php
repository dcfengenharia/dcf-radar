<?php

namespace App\Models\Concerns;

use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

trait HasObraPapel
{
    /**
     * Caches em memória por instância — sem isso, cada chamada roda uma
     * query nova. Páginas com listas grandes (Lookahead, Restrições)
     * chamam temAcessoAObra()/temPermissaoNaObra() uma vez por linha
     * renderizada — sem cache isso vira uma query idêntica repetida
     * centenas de vezes (medido: 382 queries repetidas só nisso numa obra
     * real de ~2400 atividades, 11+s de carregamento). Seguro cachear por
     * request porque o perfil do usuário na obra não muda no meio de uma
     * única renderização de página.
     *
     * $perfilIdPorObraCache: obraId => perfil_id (ou null).
     * $permissoesPorPerfilCache: perfil_id => mapa ["funcionalidade|acao" => true],
     * carregado com UMA query na primeira checagem daquele perfil — as
     * chamadas seguintes (outra funcionalidade, outra ação, mesmo perfil)
     * são só leitura de array em memória.
     */
    private array $perfilIdPorObraCache = [];

    private array $permissoesPorPerfilCache = [];

    private array $permissaoTenantCache = [];

    private function perfilIdNaObra(Work|string $obra): ?string
    {
        $obraId = $obra instanceof Work ? $obra->id : $obra;

        if (array_key_exists($obraId, $this->perfilIdPorObraCache)) {
            return $this->perfilIdPorObraCache[$obraId];
        }

        $work = $this->works()->where('works.id', $obraId)->first();

        return $this->perfilIdPorObraCache[$obraId] = $work?->pivot->perfil_id;
    }

    public function perfilNaObra(Work|string $obra): ?Perfil
    {
        $perfilId = $this->perfilIdNaObra($obra);

        return $perfilId ? Perfil::find($perfilId) : null;
    }

    public function temAcessoAObra(Work|string $obra): bool
    {
        return $this->perfilIdNaObra($obra) !== null;
    }

    public function temPermissaoNaObra(Work|string $obra, string $funcionalidade, string $acao): bool
    {
        $perfilId = $this->perfilIdNaObra($obra);

        if ($perfilId === null) {
            return false;
        }

        return $this->permissoesDoPerfil($perfilId)[$funcionalidade.'|'.$acao] ?? false;
    }

    /**
     * Pra funcionalidades tenant-level (Cadastros) sem contexto de obra
     * específico — "tem esta permissão em QUALQUER obra do tenant?".
     * Generaliza o padrão já usado por DisciplinaPolicy/
     * CategoriaRestricaoPolicy antes desta mudança ("Admin em qualquer
     * obra"), numa única query indexada (obra_user × perfil_permissoes).
     *
     * Bootstrap: se o usuário ainda não tem NENHUMA linha em obra_user
     * (tenant recém-criado, criador ainda não fez a primeira obra),
     * libera por padrão — senão o próprio criador do tenant ficaria
     * travado sem conseguir abrir Cadastros > Clientes pra cadastrar o
     * primeiro cliente antes de conseguir criar a primeira obra.
     */
    public function temPermissaoEmAlgumaObraDoTenant(string $funcionalidade, string $acao): bool
    {
        $cacheKey = $funcionalidade.'|'.$acao;

        if (array_key_exists($cacheKey, $this->permissaoTenantCache)) {
            return $this->permissaoTenantCache[$cacheKey];
        }

        if (! DB::table('obra_user')->where('user_id', $this->id)->exists()) {
            return $this->permissaoTenantCache[$cacheKey] = true;
        }

        return $this->permissaoTenantCache[$cacheKey] = DB::table('obra_user')
            ->join('perfil_permissoes', 'perfil_permissoes.perfil_id', '=', 'obra_user.perfil_id')
            ->where('obra_user.user_id', $this->id)
            ->where('perfil_permissoes.funcionalidade', $funcionalidade)
            ->where('perfil_permissoes.acao', $acao)
            ->exists();
    }

    private function permissoesDoPerfil(string $perfilId): array
    {
        if (array_key_exists($perfilId, $this->permissoesPorPerfilCache)) {
            return $this->permissoesPorPerfilCache[$perfilId];
        }

        $mapa = [];

        foreach (PerfilPermissao::where('perfil_id', $perfilId)->get(['funcionalidade', 'acao']) as $linha) {
            $mapa[$linha->funcionalidade.'|'.$linha->acao] = true;
        }

        return $this->permissoesPorPerfilCache[$perfilId] = $mapa;
    }
}
