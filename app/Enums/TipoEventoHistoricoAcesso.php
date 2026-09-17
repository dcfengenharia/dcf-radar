<?php

namespace App\Enums;

/**
 * FASE 2D — catálogo fechado de eventos de governança de acesso.
 * Deliberadamente MENOR que a lista de 16 itens (A-P) do pedido —
 * eventos que são a MESMA operação de negócio vista de ângulos
 * diferentes foram unificados (Seção 7: "histórico é de negócio, não de
 * SQL"):
 * - C (renomear) e D (descrição alterada) → `PerfilDadosAlterados`
 *   (o botão "Salvar" do editor sempre grava os dois campos juntos,
 *   numa única ação humana — nunca dois cliques separados).
 * - E (capability adicionada) e F (capability removida) →
 *   `PerfilCapabilitiesAlteradas` (`togglePermissao()`/`aplicarPreset()`
 *   sempre produzem 1 evento com adicionadas[]/removidas[], nunca 1
 *   evento por checkbox).
 * - H (atribuído) e J (conjunto substituído) → `PerfisAtribuidos` (a
 *   API de escrita, `AtribuicaoPerfilObra`, já trata "atribuir",
 *   "remover 1" e "substituir o conjunto" como a MESMA operação —
 *   "o conjunto de perfis deste par (obra, usuário) mudou de X pra Y";
 *   o resumo/diff distingue sozinho).
 * - I (zero perfis) permanece um tipo PRÓPRIO — Seção 15 pede
 *   explicitamente que isso seja "registrado claramente", nunca
 *   camuflado como uma linha a mais de `PerfisAtribuidos` com `depois`
 *   vazio.
 * - M (convite aceito) e N (convite multiperfil aceito) →
 *   `ConviteAceito` (é o MESMO evento de negócio — "o convite foi
 *   aceito e concedeu N perfis"; distinguir 1 perfil de N só pela
 *   contagem seria reintroduzir a duplicação que a Seção 7 proíbe).
 */
enum TipoEventoHistoricoAcesso: string
{
    case PerfilCriado = 'perfil_criado';
    case PerfilDuplicado = 'perfil_duplicado';
    case PerfilDadosAlterados = 'perfil_dados_alterados';
    case PerfilCapabilitiesAlteradas = 'perfil_capabilities_alteradas';
    case PerfilExcluido = 'perfil_excluido';
    case PerfisAtribuidos = 'perfis_atribuidos';
    case TodosPerfisRemovidos = 'todos_perfis_removidos';
    case UsuarioRemovidoDaObra = 'usuario_removido_da_obra';
    case ConviteEnviado = 'convite_enviado';
    case ConviteAceito = 'convite_aceito';

    public function label(): string
    {
        return match ($this) {
            self::PerfilCriado => 'Perfil criado',
            self::PerfilDuplicado => 'Perfil duplicado',
            self::PerfilDadosAlterados => 'Dados do Perfil alterados',
            self::PerfilCapabilitiesAlteradas => 'Permissões do Perfil alteradas',
            self::PerfilExcluido => 'Perfil excluído',
            self::PerfisAtribuidos => 'Acessos do usuário alterados',
            self::TodosPerfisRemovidos => 'Todos os perfis removidos',
            self::UsuarioRemovidoDaObra => 'Usuário removido da obra',
            self::ConviteEnviado => 'Convite enviado',
            self::ConviteAceito => 'Convite aceito',
        };
    }
}
