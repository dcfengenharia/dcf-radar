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
            // Pré-produção, Etapa 2.2 (achado B2, ampliado por grep de
            // padrão equivalente na seção 18 do ticket) — uma restrição
            // que o titular CRIOU pode ter sido atribuída a um responsável
            // EXTERNO (`responsavel_externo`, texto livre, mutuamente
            // exclusivo com `responsavel_id`) — nome de terceiro na mesma
            // linha, mesma classe de B2. Só filtrado por `created_by_id`;
            // `restricoes_responsavel` (acima, filtrado por
            // `responsavel_id`) nunca teve esse risco — é o próprio
            // titular quem seria o responsável nesse caso.
            'restricoes_criadas' => $this->porColunaMinimizada(
                'restricoes', 'created_by_id', $user->id, $tenantId,
                excluir: ['responsavel_externo']
            ),
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

            // Pré-produção, Etapa 2 (auditoria de privacidade — seção 2): as
            // ~41 linhas abaixo cobrem tabelas de autoria/responsabilidade
            // criadas do Ciclo 18 em diante (GRD, Take Off, Planejamento,
            // Suprimentos, Estoque, Industrialização, Inventário, Gestão),
            // nunca adicionadas aqui até agora — mesmo critério já usado nas
            // 19 tabelas acima (coluna aponta pra `users`, escopada por
            // `tenant_id`, o WHERE só retorna linhas ONDE o titular é o
            // ator — nunca dado de outra pessoa). Ver matriz completa no
            // relatório da Etapa 2 pra justificativa tabela a tabela.
            'grds_criados' => $this->porColuna('grds', 'criado_por', $user->id, $tenantId),
            'grds_emitidas' => $this->porColuna('grds', 'emitida_por', $user->id, $tenantId),
            'grd_recolhimentos_registrados' => $this->porColuna('grd_recolhimentos', 'registrado_por', $user->id, $tenantId),
            // Pré-produção, Etapa 2.2 (achado B2): a linha de
            // grd_aceites_entrega descreve DOIS papéis — quem REGISTROU o
            // aceite no sistema (este titular) e quem RECEBEU fisicamente a
            // entrega (quase sempre outra pessoa) — nome/empresa/setor/
            // assinatura são dado pessoal do RECEBEDOR, nunca do titular do
            // export, mesmo aparecendo na mesma linha que ele registrou.
            // Removidos aqui, nunca do domínio operacional (a GRD/o aceite
            // em si continuam intactos — ver App\Models\GrdAceiteEntrega,
            // Ciclo 18.5.9, e o próprio Observer que já protege a linha
            // contra edição/exclusão).
            'grd_aceites_registrados' => $this->porColunaMinimizada(
                'grd_aceites_entrega', 'registrado_por', $user->id, $tenantId,
                excluir: ['nome_recebedor_snapshot', 'empresa_snapshot', 'setor_snapshot', 'assinatura_path', 'assinatura_hash']
            ),
            'grd_aceites_invalidados' => $this->porColunaMinimizada(
                'grd_aceites_entrega', 'invalidado_por', $user->id, $tenantId,
                excluir: ['nome_recebedor_snapshot', 'empresa_snapshot', 'setor_snapshot', 'assinatura_path', 'assinatura_hash']
            ),
            'grd_alertas_recebidos' => $this->porColuna('grd_alerta_entregas', 'usuario_id', $user->id, $tenantId),
            'destinatarios_grd_vinculados_a_mim' => $this->porColuna('destinatarios', 'user_id', $user->id, $tenantId),

            'itens_take_off_criados' => $this->porColuna('itens_take_off', 'created_by_id', $user->id, $tenantId),
            'requisicoes_planejamento_criadas' => $this->porColuna('requisicoes_planejamento', 'created_by_id', $user->id, $tenantId),
            'requisicoes_planejamento_emitidas' => $this->porColuna('requisicoes_planejamento', 'emitida_por', $user->id, $tenantId),
            'alocacoes_requisicao_pacote_criadas' => $this->porColuna('alocacoes_requisicao_pacote', 'created_by_id', $user->id, $tenantId),
            'requisicoes_compra_criadas' => $this->porColuna('requisicoes_compra', 'created_by_id', $user->id, $tenantId),
            'requisicoes_compra_emitidas' => $this->porColuna('requisicoes_compra', 'emitida_por', $user->id, $tenantId),
            'requisicao_compra_etapas_realizadas' => $this->porColuna('requisicao_compra_etapas', 'realizada_por', $user->id, $tenantId),
            'pedidos_compra_criados' => $this->porColuna('pedidos_compra', 'created_by_id', $user->id, $tenantId),
            'pedidos_compra_emitidos' => $this->porColuna('pedidos_compra', 'emitido_por', $user->id, $tenantId),
            'recebimentos_pedido_registrados' => $this->porColuna('recebimentos_pedido', 'registrado_por', $user->id, $tenantId),
            'itens_suprimento_etapa_datas_atualizadas' => $this->porColuna('itens_suprimento_etapa_datas', 'atualizado_por', $user->id, $tenantId),
            'planos_acao_criados' => $this->porColuna('planos_acao', 'created_by_id', $user->id, $tenantId),
            'planos_acao_responsavel' => $this->porColuna('planos_acao', 'responsavel_id', $user->id, $tenantId),
            'inconsistencias_avanco_tratadas' => $this->porColuna('inconsistencias_avanco', 'tratado_por', $user->id, $tenantId),
            'revisoes_liberacao_alteradas' => $this->porColuna('revisao_liberacoes', 'alterado_por', $user->id, $tenantId),

            'materiais_criados' => $this->porColuna('materiais', 'created_by_id', $user->id, $tenantId),
            'unidades_estoque_criadas' => $this->porColuna('unidades_estoque', 'created_by_id', $user->id, $tenantId),
            // Pré-produção, Etapa 2.2 (achado B2): `registrado_por` é quem
            // OPEROU o sistema (este titular); `retirado_por_externo` é
            // texto livre com o nome de quem RETIROU fisicamente o
            // material — normalmente uma pessoa sem conta no sistema,
            // nunca o titular deste export. Quando o titular É o próprio
            // retirante (linhas abaixo, filtradas por `retirado_por`, a FK
            // — nunca a coluna de texto livre), essa é genuinamente a ação
            // dele, mantida sem alteração.
            'movimentacoes_estoque_registradas' => $this->porColunaMinimizada(
                'movimentacoes_estoque', 'registrado_por', $user->id, $tenantId,
                excluir: ['retirado_por_externo']
            ),
            'movimentacoes_estoque_retiradas_por_mim' => $this->porColuna('movimentacoes_estoque', 'retirado_por', $user->id, $tenantId),
            'destinacoes_planejadas_material_criadas' => $this->porColuna('destinacoes_planejadas_material', 'created_by_id', $user->id, $tenantId),
            'reservas_estoque_criadas' => $this->porColuna('reservas_estoque', 'created_by_id', $user->id, $tenantId),
            'reservas_estoque_liberadas' => $this->porColuna('reservas_estoque', 'liberado_por', $user->id, $tenantId),
            'aplicacoes_material_estoque_registradas' => $this->porColuna('aplicacoes_material_estoque', 'registrado_por', $user->id, $tenantId),
            'aplicacoes_material_estoque_atualizadas' => $this->porColuna('aplicacoes_material_estoque', 'atualizado_por', $user->id, $tenantId),
            'transferencias_estoque_registradas' => $this->porColuna('transferencias_estoque', 'registrado_por', $user->id, $tenantId),

            'ordens_industrializacao_criadas' => $this->porColuna('ordens_industrializacao', 'created_by_id', $user->id, $tenantId),
            'ordens_industrializacao_emitidas' => $this->porColuna('ordens_industrializacao', 'emitida_por', $user->id, $tenantId),
            'remessas_industrializacao_registradas' => $this->porColuna('remessas_industrializacao', 'registrado_por', $user->id, $tenantId),
            'produtos_industrializados_criados' => $this->porColuna('produtos_industrializados', 'created_by_id', $user->id, $tenantId),
            'consumos_industrializacao_registrados' => $this->porColuna('produto_industrializado_consumos', 'registrado_por', $user->id, $tenantId),
            'producoes_industrializadas_registradas' => $this->porColuna('producoes_industrializadas', 'registrado_por', $user->id, $tenantId),
            // Pré-produção, Etapa 2.2 (achado B2, ampliado por grep de
            // padrão equivalente na seção 18 do ticket) — mesmo par
            // registrado_por/retirado_por_externo de movimentacoes_estoque,
            // numa tabela irmã do domínio de Industrialização.
            'entregas_industrializacao_registradas' => $this->porColunaMinimizada(
                'entregas_produto_industrializado', 'registrado_por', $user->id, $tenantId,
                excluir: ['retirado_por_externo']
            ),
            'entregas_industrializacao_retiradas_por_mim' => $this->porColuna('entregas_produto_industrializado', 'retirado_por', $user->id, $tenantId),

            'contagens_inventario_realizadas' => $this->porColuna('contagens_inventario', 'contador_id', $user->id, $tenantId),
            'ajustes_inventario_aprovados' => $this->porColuna('inventario_ajustes', 'aprovado_por', $user->id, $tenantId),
            'inventarios_estoque_criados' => $this->porColuna('inventarios_estoque', 'created_by_id', $user->id, $tenantId),
            'inventarios_estoque_iniciados' => $this->porColuna('inventarios_estoque', 'iniciado_por', $user->id, $tenantId),
            'inventarios_estoque_concluidos' => $this->porColuna('inventarios_estoque', 'concluido_por', $user->id, $tenantId),
            'inventarios_estoque_cancelados' => $this->porColuna('inventarios_estoque', 'cancelado_por', $user->id, $tenantId),

            'comunicacoes_situacao_recebidas' => $this->porColuna('situacao_comunicacao_entregas', 'usuario_id', $user->id, $tenantId),
            'programacao_semanal_itens_realizados' => $this->porColuna('programacao_semanal_itens', 'realizado_por', $user->id, $tenantId),
            'programacoes_semanais_fechadas' => $this->porColuna('programacoes_semanais', 'fechada_por', $user->id, $tenantId),

            // `tenants` não tem coluna `tenant_id` (é a própria linha do
            // tenant) — nunca cabe no helper genérico `porColuna()`, que
            // sempre assume esse filtro. Fato pequeno e inequívoco: "eu fui
            // quem criou esta empresa" — sem risco de terceiro (tenant só
            // tem 1 criador).
            'empresa_que_criei' => DB::table('tenants')
                ->where('id', $tenantId)
                ->where('criado_por_id', $user->id)
                ->get()
                ->map(fn ($linha) => (array) $linha)
                ->all(),

            // Decisão pendente, registrada aqui e não implementada nesta
            // etapa (ver relatório da Etapa 2, seção 2): `obra_user`/
            // `tenant_user`/`aviso_plataforma_dispensas` (vínculo/preferência,
            // não autoria de conteúdo — nenhuma tem `tenant_id`, não cabem
            // no helper genérico) e `avisos_plataforma.criado_por_id`
            // (conteúdo da PLATAFORMA, não do tenant — também sem
            // `tenant_id`, só relevante pra um admin da plataforma que
            // autorou um aviso). Nenhuma delas foi incluída automaticamente.
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

    /**
     * Pré-produção, Etapa 2.2 (achado B2) — mesma consulta de
     * `porColuna()`, mas removendo do array retornado as colunas
     * explicitamente listadas em `$excluir`. Nunca altera/apaga nada no
     * banco (o domínio operacional continua com a linha completa) — só
     * minimiza o PACOTE entregue ao titular no export individual, quando
     * a linha carrega dado pessoal estruturado de uma TERCEIRA pessoa
     * (nunca o titular) como coluna irmã da coluna de autoria filtrada.
     */
    private function porColunaMinimizada(string $tabela, string $coluna, string $userId, string $tenantId, array $excluir): array
    {
        return DB::table($tabela)
            ->where($coluna, $userId)
            ->where('tenant_id', $tenantId)
            ->get()
            ->map(fn ($linha) => array_diff_key((array) $linha, array_flip($excluir)))
            ->all();
    }
}
