<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Exportação de dados pessoais (LGPD/portabilidade) — mirror de
 * App\Actions\Jetstream\DeleteUser, mas pro lado de "me dê tudo" em vez
 * de "apague tudo". Monta um array serializável com os dados do próprio
 * usuário + todo registro onde ele aparece como autor/responsável,
 * usando as tabelas de FK já mapeadas (restrictOnDelete/nullOnDelete)
 * que apontam pra `users`.
 *
 * Consulta direto via DB::table() (não Eloquent) de propósito: evita
 * depender de relação definida em cada um dos ~20 models envolvidos só
 * pra este único caso de uso, e cada tabela já tem tenant_id — filtrado
 * explicitamente em toda query, como defesa extra (isolamento de tenant
 * é a prioridade #1 de segurança deste projeto).
 */
class ExportUserData
{
    public function gerar(User $user): array
    {
        $tenantId = $user->tenant_id;

        return [
            'usuario' => [
                'id' => $user->id,
                'nome' => trim("{$user->first_name} {$user->last_name}"),
                'email' => $user->email,
                'cargo' => $user->cargo,
                'telefone' => $user->telefone,
                'criado_em' => $user->created_at?->toIso8601String(),
            ],
            'atividades_responsavel' => $this->porColuna('atividades', 'responsavel_id', $user->id, $tenantId),
            'atividades_criadas' => $this->porColuna('atividades', 'created_by_id', $user->id, $tenantId),
            'restricoes_responsavel' => $this->porColuna('restricoes', 'responsavel_id', $user->id, $tenantId),
            'restricoes_criadas' => $this->porColuna('restricoes', 'created_by_id', $user->id, $tenantId),
            'causas_nao_cumprimento_criadas' => $this->porColuna('causas_nao_cumprimento', 'created_by_id', $user->id, $tenantId),
            'restricao_acoes' => $this->porColuna('restricao_acoes', 'autor_id', $user->id, $tenantId),
            'curva_ajustes' => $this->porColuna('curva_ajustes', 'ajustado_por', $user->id, $tenantId),
            'atividade_itens_prontidao_concluidos' => $this->porColuna('atividade_itens_prontidao', 'concluido_por', $user->id, $tenantId),
            'cronograma_importacoes' => $this->porColuna('cronograma_importacoes', 'user_id', $user->id, $tenantId),
            'linhas_base_criadas' => $this->porColuna('linhas_base', 'criado_por', $user->id, $tenantId),
            'atividade_comentarios' => $this->porColuna('atividade_comentarios', 'autor_id', $user->id, $tenantId),
            'convites_enviados' => $this->porColuna('convites', 'convidado_por_id', $user->id, $tenantId),
            'reports_criados' => $this->porColuna('reports', 'criado_por', $user->id, $tenantId),
            'reports_emitidos' => $this->porColuna('reports', 'emitido_por', $user->id, $tenantId),
            'report_fotos_enviadas' => $this->porColuna('report_fotos', 'enviado_por', $user->id, $tenantId),
            'report_comentarios' => $this->porColuna('report_comentarios', 'autor_id', $user->id, $tenantId),
            'programacoes_semanais_criadas' => $this->porColuna('programacoes_semanais', 'criado_por', $user->id, $tenantId),
            'programacao_semanal_itens_criados' => $this->porColuna('programacao_semanal_itens', 'criado_por', $user->id, $tenantId),
            'itens_suprimento_responsavel' => $this->porColuna('itens_suprimento', 'responsavel_id', $user->id, $tenantId),
            'itens_suprimento_criados' => $this->porColuna('itens_suprimento', 'created_by_id', $user->id, $tenantId),
            'item_suprimento_comentarios' => $this->porColuna('item_suprimento_comentarios', 'autor_id', $user->id, $tenantId),
            'documento_engenharia_revisoes_criadas' => $this->porColuna('documento_engenharia_revisoes', 'criado_por_id', $user->id, $tenantId),
            'documento_engenharia_reprogramacoes_criadas' => $this->porColuna('documento_engenharia_reprogramacoes', 'criado_por_id', $user->id, $tenantId),
            'feedbacks_enviados' => $this->porColuna('feedbacks', 'user_id', $user->id, $tenantId),
        ];
    }

    private function porColuna(string $tabela, string $coluna, string $userId, string $tenantId): array
    {
        return DB::table($tabela)
            ->where($coluna, $userId)
            ->where('tenant_id', $tenantId)
            ->get()
            ->map(fn ($linha) => (array) $linha)
            ->all();
    }
}
