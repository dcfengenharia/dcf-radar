<?php

namespace App\Support\Perfis;

use App\Enums\OrigemEventoHistoricoAcesso;
use App\Enums\TipoEventoHistoricoAcesso;
use App\Models\HistoricoAcesso;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\User;
use App\Models\Work;
use App\Support\ImpersonationContext;
use Illuminate\Support\Collection;

/**
 * FASE 2D — ÚNICO ponto de escrita de `historico_acessos` (Seção 29:
 * "evitar `HistoricoAcesso::create()` espalhado por 20 componentes").
 * Cada método aqui corresponde a UM evento de NEGÓCIO (nunca um evento
 * técnico de SQL) — quem chama nunca monta o `resumo`/`detalhes` na mão,
 * só entrega os dados de domínio já resolvidos (Perfis, usuários, obra).
 *
 * Toda escrita AQUI espera já estar dentro da transação do CALLER
 * (Seção 31: "mudança de acesso + evento de auditoria... mesma
 * transação") — esta classe nunca abre `DB::transaction()` própria,
 * pra nunca competir/aninhar desnecessariamente com a transação de quem
 * já está gravando a mudança real. Se o `HistoricoAcesso::create()`
 * lançar (ex.: falha de banco), a exceção sobe crua — o caller, dentro
 * da MESMA transação, reverte tudo (Seção 32: "não engolir exception
 * silenciosamente").
 *
 * "Ator explícito" (Seção 25/26) — todo método exige `User $ator`
 * (nunca resolvido via `Auth::user()` internamente): quem chama já
 * capturou o usuário autenticado real no ponto de comando, inclusive
 * sob impersonation (o ator continua sendo o admin da plataforma, nunca
 * o tenant impersonado — `contextoAtor()` grava isso explicitamente).
 */
class RegistrarEventoAcesso
{
    private static function nomeCompleto(User $user): string
    {
        return trim($user->first_name.' '.$user->last_name);
    }

    /**
     * Seção 25/26 — contexto do ator, sempre capturado explicitamente.
     * `ator_impersonando` só é `true` quando o ator É platform admin E
     * existe uma impersonation ATIVA na sessão corrente — nunca inferido
     * de outra forma (mesma checagem já usada pelo Gate
     * `gerenciar-perfis-acesso`).
     *
     * @return array<string, mixed>
     */
    private static function contextoAtor(User $ator): array
    {
        return [
            'ator_user_id' => $ator->id,
            'ator_nome_snapshot' => self::nomeCompleto($ator),
            'ator_platform_admin' => (bool) $ator->is_platform_admin,
            'ator_impersonando' => (bool) $ator->is_platform_admin && ImpersonationContext::currentTenantId() !== null,
        ];
    }

    /**
     * @param  array<int, array{funcionalidade: string, acao: string}>  $capacidades
     * @return array<int, string>
     */
    private static function rotularCapacidades(array $capacidades): array
    {
        $nomes = collect(\App\Support\CatalogoFuncionalidades::todas())->keyBy('slug');

        return collect($capacidades)
            ->map(function ($c) use ($nomes) {
                $nomeFuncionalidade = $nomes->get($c['funcionalidade'])['nome'] ?? $c['funcionalidade'];

                return $nomeFuncionalidade.' — '.CapabilidadeCatalogo::nomeAcao($c['acao']);
            })
            ->sort()->values()->all();
    }

    // =========================================================================
    // PERFIL — CRUD (origem sempre PerfilAcesso, único entry point hoje)
    // =========================================================================

    /**
     * Evento A — Seção 19: nome/descrição + origem (zero/template/
     * existente) + snapshot das capacidades iniciais.
     */
    public static function perfilCriado(User $ator, Perfil $perfil, string $origemCriacao, ?string $nomeReferencia = null): HistoricoAcesso
    {
        $capacidades = PerfilPermissao::where('perfil_id', $perfil->id)->get(['funcionalidade', 'acao'])
            ->map(fn ($p) => ['funcionalidade' => $p->funcionalidade, 'acao' => $p->acao])->all();

        $rotuloOrigem = match ($origemCriacao) {
            'template' => "a partir do template \"{$nomeReferencia}\"",
            'existente' => "a partir do perfil \"{$nomeReferencia}\"",
            default => 'do zero',
        };

        return HistoricoAcesso::create([
            ...self::contextoAtor($ator),
            'tenant_id' => $perfil->tenant_id,
            'tipo_evento' => TipoEventoHistoricoAcesso::PerfilCriado,
            'origem' => OrigemEventoHistoricoAcesso::PerfilAcesso,
            'perfil_id' => $perfil->id,
            'perfil_nome_snapshot' => $perfil->nome,
            'resumo' => self::nomeCompleto($ator)." criou o perfil \"{$perfil->nome}\" ({$rotuloOrigem}).",
            'detalhes' => [
                'origem_criacao' => $origemCriacao,
                'nome_referencia' => $nomeReferencia,
                'capacidades_iniciais' => self::rotularCapacidades($capacidades),
            ],
        ]);
    }

    /**
     * Evento B — Seção 20: nunca copia o HISTÓRICO do perfil original,
     * só registra a duplicação em si + snapshot das capacidades da
     * cópia (idênticas às do original NO INSTANTE da duplicação).
     */
    public static function perfilDuplicado(User $ator, Perfil $original, Perfil $copia): HistoricoAcesso
    {
        $capacidades = PerfilPermissao::where('perfil_id', $copia->id)->get(['funcionalidade', 'acao'])
            ->map(fn ($p) => ['funcionalidade' => $p->funcionalidade, 'acao' => $p->acao])->all();

        return HistoricoAcesso::create([
            ...self::contextoAtor($ator),
            'tenant_id' => $copia->tenant_id,
            'tipo_evento' => TipoEventoHistoricoAcesso::PerfilDuplicado,
            'origem' => OrigemEventoHistoricoAcesso::PerfilAcesso,
            'perfil_id' => $copia->id,
            'perfil_nome_snapshot' => $copia->nome,
            'resumo' => self::nomeCompleto($ator)." duplicou \"{$original->nome}\" criando \"{$copia->nome}\".",
            'detalhes' => [
                'perfil_origem_id' => $original->id,
                'perfil_origem_nome_snapshot' => $original->nome,
                'capacidades_iniciais' => self::rotularCapacidades($capacidades),
            ],
        ]);
    }

    /**
     * Evento C/D — Seção 21/22: SÓ registra o que de fato mudou; `null`
     * quando nome E descrição permanecem idênticos (Seção 21: "salvar
     * sem alteração não cria evento").
     */
    public static function perfilDadosAlterados(
        User $ator,
        Perfil $perfil,
        string $nomeAntes,
        string $nomeDepois,
        ?string $descricaoAntes,
        ?string $descricaoDepois
    ): ?HistoricoAcesso {
        $nomeMudou = $nomeAntes !== $nomeDepois;
        $descricaoMudou = $descricaoAntes !== $descricaoDepois;

        if (! $nomeMudou && ! $descricaoMudou) {
            return null;
        }

        $partes = [];
        if ($nomeMudou) {
            $partes[] = "renomeou o perfil de \"{$nomeAntes}\" para \"{$nomeDepois}\"";
        }
        if ($descricaoMudou) {
            $partes[] = $nomeMudou ? 'e alterou a descrição' : "alterou a descrição do perfil \"{$nomeDepois}\"";
        }

        return HistoricoAcesso::create([
            ...self::contextoAtor($ator),
            'tenant_id' => $perfil->tenant_id,
            'tipo_evento' => TipoEventoHistoricoAcesso::PerfilDadosAlterados,
            'origem' => OrigemEventoHistoricoAcesso::PerfilAcesso,
            'perfil_id' => $perfil->id,
            'perfil_nome_snapshot' => $nomeDepois,
            'resumo' => self::nomeCompleto($ator).' '.implode(' ', $partes).'.',
            'detalhes' => [
                'nome_antes' => $nomeMudou ? $nomeAntes : null,
                'nome_depois' => $nomeMudou ? $nomeDepois : null,
                'descricao_antes' => $descricaoMudou ? $descricaoAntes : null,
                'descricao_depois' => $descricaoMudou ? $descricaoDepois : null,
            ],
        ]);
    }

    /**
     * Evento E/F — Seção 10/11: `$antes`/`$depois` são arrays de
     * `['funcionalidade'=>...,'acao'=>...]` (o mesmo formato já usado
     * por `PerfilPermissao`). `null` quando o conjunto não muda (defesa
     * — hoje `togglePermissao()`/`aplicarPreset()` sempre produzem uma
     * mudança real, mas nunca custa garantir). Impacto (Seção 11)
     * sempre reflete QUEM TEM o perfil AGORA — editar capacidades nunca
     * muda a atribuição, só o efeito de quem já tem.
     *
     * @param  array<int, array{funcionalidade: string, acao: string}>  $antes
     * @param  array<int, array{funcionalidade: string, acao: string}>  $depois
     */
    public static function perfilCapabilitiesAlteradas(User $ator, Perfil $perfil, array $antes, array $depois): ?HistoricoAcesso
    {
        $chave = fn (array $c) => $c['funcionalidade'].'|'.$c['acao'];
        $chavesAntes = collect($antes)->map($chave)->all();
        $chavesDepois = collect($depois)->map($chave)->all();

        $adicionadas = collect($depois)->reject(fn ($c) => in_array($chave($c), $chavesAntes, true))->values()->all();
        $removidas = collect($antes)->reject(fn ($c) => in_array($chave($c), $chavesDepois, true))->values()->all();

        if ($adicionadas === [] && $removidas === []) {
            return null;
        }

        $impacto = ImpactoPerfilCalculator::calcular($perfil->tenant_id, $perfil->id);

        return HistoricoAcesso::create([
            ...self::contextoAtor($ator),
            'tenant_id' => $perfil->tenant_id,
            'tipo_evento' => TipoEventoHistoricoAcesso::PerfilCapabilitiesAlteradas,
            'origem' => OrigemEventoHistoricoAcesso::PerfilAcesso,
            'perfil_id' => $perfil->id,
            'perfil_nome_snapshot' => $perfil->nome,
            'resumo' => self::nomeCompleto($ator)." alterou as permissões do perfil \"{$perfil->nome}\".",
            'detalhes' => [
                'adicionadas' => self::rotularCapacidades($adicionadas),
                'removidas' => self::rotularCapacidades($removidas),
                'impacto' => ['usuarios' => $impacto['usuarios'], 'obras' => $impacto['obras']],
            ],
        ]);
    }

    /**
     * Evento G — Seção 23: SEMPRE chamado ANTES do `$perfil->delete()`
     * (o snapshot precisa do estado vivo — nome/capacidades — no
     * instante da exclusão).
     */
    public static function perfilExcluido(User $ator, Perfil $perfil): HistoricoAcesso
    {
        $capacidades = PerfilPermissao::where('perfil_id', $perfil->id)->get(['funcionalidade', 'acao'])
            ->map(fn ($p) => ['funcionalidade' => $p->funcionalidade, 'acao' => $p->acao])->all();

        return HistoricoAcesso::create([
            ...self::contextoAtor($ator),
            'tenant_id' => $perfil->tenant_id,
            'tipo_evento' => TipoEventoHistoricoAcesso::PerfilExcluido,
            'origem' => OrigemEventoHistoricoAcesso::PerfilAcesso,
            'perfil_id' => $perfil->id,
            'perfil_nome_snapshot' => $perfil->nome,
            'resumo' => self::nomeCompleto($ator)." excluiu o perfil \"{$perfil->nome}\".",
            'detalhes' => [
                'descricao_no_momento' => $perfil->descricao,
                'capacidades_no_momento' => self::rotularCapacidades($capacidades),
            ],
        ]);
    }

    // =========================================================================
    // ATRIBUIÇÃO — usuário × obra × perfis (Matriz de Acessos / Equipe da Obra)
    // =========================================================================

    /**
     * Eventos H/I/J — Seção 12-15: cobre adicionar, remover parcial e
     * substituir o conjunto inteiro — é sempre a MESMA pergunta ("o
     * conjunto de perfis deste par mudou de X pra Y?"). `null` quando o
     * conjunto (por ID) não muda de fato (Seção 7/14: "não registrar
     * Planejamento como alteração se permaneceu" — só a diferença real
     * é que importa). Delega pra `todosPerfisRemovidos()` (tipo PRÓPRIO,
     * Seção 15) quando o resultado é zero perfis.
     *
     * @param  Collection<int, Perfil>  $perfisAntes
     * @param  Collection<int, Perfil>  $perfisDepois
     */
    public static function perfisAtribuidos(
        User $ator,
        Work $obra,
        User $usuarioAfetado,
        Collection $perfisAntes,
        Collection $perfisDepois,
        OrigemEventoHistoricoAcesso $origem
    ): ?HistoricoAcesso {
        $idsAntes = $perfisAntes->pluck('id')->sort()->values()->all();
        $idsDepois = $perfisDepois->pluck('id')->sort()->values()->all();

        if ($idsAntes === $idsDepois) {
            return null;
        }

        if ($perfisDepois->isEmpty() && $perfisAntes->isNotEmpty()) {
            return self::todosPerfisRemovidos($ator, $obra, $usuarioAfetado, $perfisAntes, $origem);
        }

        $adicionados = $perfisDepois->reject(fn (Perfil $p) => in_array($p->id, $idsAntes, true))->values();
        $removidos = $perfisAntes->reject(fn (Perfil $p) => in_array($p->id, $idsDepois, true))->values();

        return HistoricoAcesso::create([
            ...self::contextoAtor($ator),
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'tipo_evento' => TipoEventoHistoricoAcesso::PerfisAtribuidos,
            'origem' => $origem,
            'usuario_afetado_id' => $usuarioAfetado->id,
            'usuario_afetado_nome_snapshot' => self::nomeCompleto($usuarioAfetado),
            'resumo' => self::nomeCompleto($ator).' alterou os acessos de '.self::nomeCompleto($usuarioAfetado)." na obra \"{$obra->name}\".",
            'detalhes' => [
                'adicionadas' => $adicionados->map(fn (Perfil $p) => ['id' => $p->id, 'nome' => $p->nome])->values()->all(),
                'removidas' => $removidos->map(fn (Perfil $p) => ['id' => $p->id, 'nome' => $p->nome])->values()->all(),
            ],
        ]);
    }

    /**
     * Evento I — Seção 15: caso especial de zero perfis, SEMPRE
     * distinto de "usuário removido da obra" (Seção 16) — o usuário
     * PERMANECE membro, só perde toda capacidade.
     *
     * @param  Collection<int, Perfil>  $perfisAntes
     */
    public static function todosPerfisRemovidos(
        User $ator,
        Work $obra,
        User $usuarioAfetado,
        Collection $perfisAntes,
        OrigemEventoHistoricoAcesso $origem
    ): HistoricoAcesso {
        return HistoricoAcesso::create([
            ...self::contextoAtor($ator),
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'tipo_evento' => TipoEventoHistoricoAcesso::TodosPerfisRemovidos,
            'origem' => $origem,
            'usuario_afetado_id' => $usuarioAfetado->id,
            'usuario_afetado_nome_snapshot' => self::nomeCompleto($usuarioAfetado),
            'resumo' => self::nomeCompleto($ator).' removeu todos os perfis de acesso de '.self::nomeCompleto($usuarioAfetado)." na obra \"{$obra->name}\". O usuário permaneceu membro da obra.",
            'detalhes' => [
                'removidas' => $perfisAntes->map(fn (Perfil $p) => ['id' => $p->id, 'nome' => $p->nome])->values()->all(),
            ],
        ]);
    }

    /**
     * Evento K — Seção 16: SEPARADO semanticamente de "perfis
     * removidos" — aqui o usuário deixa de ser MEMBRO da obra. Snapshot
     * dos perfis que possuía antes da remoção (Section 16).
     *
     * @param  Collection<int, Perfil>  $perfisAntes
     */
    public static function usuarioRemovidoDaObra(
        User $ator,
        Work $obra,
        User $usuarioAfetado,
        Collection $perfisAntes,
        OrigemEventoHistoricoAcesso $origem
    ): HistoricoAcesso {
        return HistoricoAcesso::create([
            ...self::contextoAtor($ator),
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'tipo_evento' => TipoEventoHistoricoAcesso::UsuarioRemovidoDaObra,
            'origem' => $origem,
            'usuario_afetado_id' => $usuarioAfetado->id,
            'usuario_afetado_nome_snapshot' => self::nomeCompleto($usuarioAfetado),
            'resumo' => self::nomeCompleto($ator).' removeu '.self::nomeCompleto($usuarioAfetado)." da equipe da obra \"{$obra->name}\".",
            'detalhes' => [
                'perfis_no_momento_da_remocao' => $perfisAntes->map(fn (Perfil $p) => ['id' => $p->id, 'nome' => $p->nome])->values()->all(),
            ],
        ]);
    }

    // =========================================================================
    // CONVITE
    // =========================================================================

    /**
     * Evento L — Seção 17/18: guarda o e-mail (necessário pra
     * auditabilidade — é o alvo do convite, Seção 18), NUNCA o token.
     *
     * @param  Collection<int, Perfil>  $perfis
     */
    public static function conviteEnviado(User $ator, Work $obra, string $email, Collection $perfis): HistoricoAcesso
    {
        $n = $perfis->count();

        return HistoricoAcesso::create([
            ...self::contextoAtor($ator),
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'tipo_evento' => TipoEventoHistoricoAcesso::ConviteEnviado,
            'origem' => OrigemEventoHistoricoAcesso::Convite,
            'resumo' => self::nomeCompleto($ator)." convidou {$email} para a obra \"{$obra->name}\" com {$n} perfil(is).",
            'detalhes' => [
                'email_convidado' => $email,
                'perfis_convidados' => $perfis->map(fn (Perfil $p) => ['id' => $p->id, 'nome' => $p->nome])->values()->all(),
            ],
        ]);
    }

    /**
     * Evento M/N unificado — Seção 17: registrado no aceite, SEPARADO
     * do evento de envio (o mesmo `token`/convite pode ser correlacionado
     * pela obra+e-mail, nunca guardado aqui). O ATOR é o próprio usuário
     * que aceita (Seção 25 — é quem de fato executou a ação nesse
     * instante; a AUTORIDADE de conceder os perfis já foi decidida por
     * quem enviou, registrado como contexto em `detalhes`, nunca como
     * ator deste evento).
     *
     * @param  Collection<int, Perfil>  $perfisConcedidos
     */
    public static function conviteAceito(
        User $usuarioQueAceitou,
        Work $obra,
        Collection $perfisConcedidos,
        string $emailConvite,
        ?User $convidadoPor = null
    ): HistoricoAcesso {
        $n = $perfisConcedidos->count();

        return HistoricoAcesso::create([
            ...self::contextoAtor($usuarioQueAceitou),
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'tipo_evento' => TipoEventoHistoricoAcesso::ConviteAceito,
            'origem' => OrigemEventoHistoricoAcesso::Convite,
            'usuario_afetado_id' => $usuarioQueAceitou->id,
            'usuario_afetado_nome_snapshot' => self::nomeCompleto($usuarioQueAceitou),
            'resumo' => self::nomeCompleto($usuarioQueAceitou)." aceitou o convite para a obra \"{$obra->name}\", recebendo {$n} perfil(is).",
            'detalhes' => [
                'email_convite' => $emailConvite,
                'convidado_por_nome_snapshot' => $convidadoPor ? self::nomeCompleto($convidadoPor) : null,
                'perfis_concedidos' => $perfisConcedidos->map(fn (Perfil $p) => ['id' => $p->id, 'nome' => $p->nome])->values()->all(),
            ],
        ]);
    }
}
