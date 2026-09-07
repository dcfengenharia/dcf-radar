<?php

namespace App\Actions\Jetstream;

use App\Models\User;
use Laravel\Jetstream\Contracts\DeletesUsers;

/**
 * Pré-produção, Etapa 2 (seção 3/4) — provado por probe descartável que um
 * usuário representa não só uma conta de login, mas uma IDENTIDADE
 * HISTÓRICA DE AUTORIA: a auditoria da FK real (`SHOW CREATE TABLE`) contra
 * as ~68 colunas que apontam pra `users` mostrou que a maioria usa
 * `nullOnDelete()` — um `$user->delete()` bem-sucedido NÃO é bloqueado por
 * elas, só apaga silenciosamente a atribuição de autoria em dezenas de
 * tabelas (Restrição, Atividade, GRD, Suprimentos, Estoque, Industrialização
 * continuam existindo, mas "quem fez isso" vira NULL pra sempre). Só 7
 * tabelas (`restricao_acoes`/`atividade_comentarios`/`convites`/
 * `reports.criado_por`/`report_comentarios`/`item_suprimento_comentarios`/
 * `feedbacks`) usam `restrictOnDelete()` e bloqueiam a exclusão com uma
 * `QueryException` crua — nenhum dos dois comportamentos é aceitável pra um
 * botão de autoatendimento.
 *
 * Solução: reaproveitar a infraestrutura de DESATIVAÇÃO já existente e já
 * em produção (`users.ativo`, alternada hoje só pelo admin da plataforma em
 * /admin/usuarios) — `App\Http\Middleware\BloquearUsuarioInativo` já roda
 * GLOBALMENTE no grupo `web` e já desloga/bloqueia em qualquer request
 * seguinte, sem precisar de nenhuma invalidação de sessão especial aqui
 * (o `Auth::logout()`/`session()->invalidate()` que o próprio
 * `Laravel\Jetstream\Http\Livewire\DeleteUserForm::deleteUser()` já chama
 * logo depois desta Action cobre a sessão ATUAL). "Excluir minha conta"
 * passa a significar "desativar meu acesso, preservando toda a identidade
 * histórica de autoria" — NUNCA um hard delete. Nenhuma migration nova
 * (a coluna já existe desde a Gestão de Usuários do admin), nenhum
 * SoftDeletes adicionado (evita todos os impactos de scope/uniqueness/
 * reativação que SoftDeletes traria — ver relatório da Etapa 2, seção 4).
 *
 * Apagar de verdade uma identidade histórica (anonimização/purge real)
 * continua fora de escopo — decisão jurídica pendente, não implementada
 * automaticamente (ver relatório da Etapa 2).
 */
class DeleteUser implements DeletesUsers
{
    /**
     * Desativa o acesso do usuário — nunca apaga a identidade histórica.
     */
    public function delete(User $user): void
    {
        $user->deleteProfilePhoto();
        $user->tokens->each->delete();
        $user->update(['ativo' => false]);
    }
}
