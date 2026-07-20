# BRIEFING — DCF Radar para o Claude Code

Documento autoritativo das decisões consolidadas. Use junto com o
`CLAUDE.md` (carregado automaticamente a cada sessão) e a implementação
de referência em `/referencia/dcf-radar`.

> Contexto de parceria: o projeto está no **início, com algo já em
> andamento**. O papel do Claude Code aqui não é recriar do zero, e sim
> **adequar o que já existe** ao alvo abaixo — por isso o trabalho começa
> sempre por uma auditoria, nunca por uma alteração.

---

## 1. Decisões consolidadas (registro completo)

| # | Decisão | Por quê |
|---|---|---|
| 1 | Multitenant em **banco único**, isolamento por linha (`tenant_id`) | Permite relatórios e benchmarking entre obras; migrations triviais |
| 2 | Isolamento via trait `BelongsToTenant` + global scope **próprios** (sem pacote) | O filtro é segurança crítica; queremos código auditável |
| 3 | **Dois contextos**: tenant = fronteira de segurança (automática); obra = contexto de trabalho (selecionável) | Não travar gerente multi-obra nem vazar dados entre empresas |
| 4 | Chaves **ULID** em tudo | Não vaza volume na URL, ordenável por tempo, pronto para sharding |
| 5 | Tenant resolvido pelo **usuário autenticado**; e-mail global único; subdomínio = v2 | Simples e seguro no MVP |
| 6 | Papéis na pivot `obra_user` (enum `Papel`), enforçados por **Policies** nativas | Papel é por obra; spatie é global-first |
| 7 | **Cliente** é uma **entidade** (`clientes`, por tenant); a Obra pertence a um Cliente | Gerenciar contratantes como registros (substitui o campo texto antigo) |
| 8 | **Acesso de cliente é somente leitura**: usuário com papel `cliente_leitura` na obra, criado dentro do tenant da construtora; federação cross-org = v2 | Dar visão ao contratante (ex: Vopak) sem complexidade de federação |
| 9 | Hierarquia Tenant → Cliente → Obra → PacoteTrabalho (EAP auto-aninhada) → Atividade → Restricao → RestricaoAcao; Causa pendura em Atividade; Disciplina classifica Atividade | Empresa tem vários clientes/obras; papéis variam por obra |
| 10 | Categorias de restrição **configuráveis** por tenant, mapeadas a 1 dos 5 pilares Lean (`pilar_lean`) | Concilia vocabulário próprio com dashboards padronizados |
| 11 | Responsável da restrição = **usuário real** (FK) | Para notificar e cobrar |
| 12 | **Prontidão derivada** das restrições (não é coluna nem tabela) | Elimina digitação dupla e dados conflitantes |
| 13 | **Bloqueio**: atividade não-pronta não entra no plano semanal | Impõe o filtro de confiabilidade do LPS |
| 14 | Restrição guarda `bloqueante`, `probabilidade`×`impacto` (score P×I), `prazo_limite`, `aberta_em`, `resolvida_em` | Radar preditivo + lead time real |
| 15 | Histórico em `restricao_acoes` (autor + data) em vez de sobrescrever a ação | Memória do que já foi tentado |
| 16 | Causa de não cumprimento **obrigatória** ao marcar atividade `nao_concluido` | Fecha o loop de aprendizado |
| 17 | `caminho_critico` = **flag manual** no MVP (não CPM automático) | Honestidade: CPM real exige integração com P6 |
| 18 | **Soft deletes + autoria** nas tabelas de planejamento | Auditoria de planejamento |
| 19 | Defesa em profundidade: scope filtra só com tenant setado; console usa `actingAs`; `TenantIsolationTest` no pipeline; proibido `withoutGlobalScope` em request | Seguro contra vazamento entre concorrentes |

---

## 2. Modelo de dados completo

Camada de organização/conta (fundação):
- **tenants** — a empresa (fronteira de isolamento; sem `tenant_id`).
- **users** — `tenant_id`, `nome`, `email` (único global), ULID.
- **clientes** — `tenant_id`, `nome`, `documento` (CNPJ, opcional).
- **obras** — `tenant_id`, `cliente_id` (FK, opcional), `nome`, `status`,
  `inicio_previsto`, `fim_previsto`.
- **obra_user** (pivot) — `obra_id`, `user_id`, `papel`.

Camada de planejamento (domínio):
- **disciplinas** — `tenant_id`, `nome` (catálogo por empresa).
- **categorias_restricao** — `tenant_id`, `nome`, `pilar_lean`.
- **pacotes_trabalho** — `tenant_id`, `obra_id`, `parent_id` (EAP
  auto-aninhada), `codigo`, `nome`.
- **atividades** — `tenant_id`, `obra_id`, `pacote_trabalho_id`,
  `disciplina_id`, `responsavel_id`, `data_planejada`, `caminho_critico`,
  `status` (estados Last Planner).
- **restricoes** — `tenant_id`, `atividade_id`, `categoria_id`,
  `responsavel_id`, `bloqueante`, `probabilidade`, `impacto`,
  `prazo_limite`, `status`, `aberta_em`, `resolvida_em`.
- **restricao_acoes** — `tenant_id`, `restricao_id`, `user_id`, `descricao`.
- **causas_nao_cumprimento** — `tenant_id`, `atividade_id`,
  `categoria_causa`, `descricao`.

Enums:
- `Papel`: admin, gerente_planejamento, engenheiro, encarregado,
  **cliente_leitura**.
- `status` da atividade: planejado, comprometido, em_execucao, concluido,
  nao_concluido.
- `status` da restrição: aberta, em_tratamento, aguardando_terceiros,
  resolvida (vencida/crítica são **derivadas** de `prazo_limite` e do score).

---

## 3. Regras de negócio centrais

1. Atividade pronta = ZERO restrições bloqueantes não resolvidas
   (prontidão derivada por consulta).
2. Só atividade pronta pode ser puxada para o plano semanal.
3. Marcar `nao_concluido` exige registrar uma Causa.
4. Lead time de remoção = `resolvida_em` − `aberta_em`.
5. Score de risco = `probabilidade` × `impacto`.

---

## 4. Visão de produto (diferenciais que tornam essencial)

Ancorados no Anexo IV (Norma de Coordenação) da Vopak:
- **Gerador do pacote semanal de quinta-feira** — monta sozinho o
  relatório que o contrato exige (lookahead, controle de materiais, etc.).
- **Alerta de risco de suprimentos contratual** — cruza o `prazo_limite`
  da restrição de material com os prazos de compra do contrato (21 dias
  fornecedor novo / 10 dias cadastrado) e sinaliza o que é matematicamente
  impossível de resolver a tempo.
- **Visão de leitura para o cliente** — link vivo da obra para o
  contratante, em vez de PDF.
- **Benchmarking anônimo entre obras** — por categoria/pilar de restrição.

(Itens de produto, não de schema — entram nas ondas finais.)

---

## 5. Protocolo de trabalho (NÃO pule etapas)

1. **Branch.** `git checkout -b feat/multitenant-lps`
2. **Auditoria primeiro.** O Claude Code lê o estado atual e produz um
   relatório de lacunas. Não altera nada nesta etapa.
3. **Você decide os conflitos** (ULID vs bigint se já houver dados;
   renomeação de colunas; tabelas que ganham `tenant_id`; campo
   `contratante` antigo → entidade `clientes`).
4. **Plan Mode.** O Claude Code propõe o plano; você aprova.
5. **Execução em ondas**, uma de cada vez, revisando o diff.
6. **Portão de qualidade.** Cada onda só fecha com `php artisan test`
   verde — em especial o `TenantIsolationTest`.

> Risco destrutivo: se já houver dados em produção com chave `bigint`,
> migrar para ULID reescreve chaves e foreign keys. É decisão de negócio
> — exija o plano de dados (com backup) antes de qualquer alteração.

---

## 6. Roadmap por ondas

- **Onda 0 — Reconciliação.** Auditoria + plano aprovado.
- **Onda 1 — Fundação/organização:** trait/scope/middleware + tabelas
  `tenants`, `users`, `clientes`, `obras`, `obra_user` +
  `TenantIsolationTest`. (Forma-alvo em `/referencia/dcf-radar`.)
- **Onda 2 — Planejamento:** `disciplinas`, `categorias_restricao`,
  `pacotes_trabalho`, `atividades`, `restricoes`, `restricao_acoes`,
  `causas_nao_cumprimento` — todas com `BelongsToTenant`.
- **Onda 3 — Regras de negócio:** prontidão derivada, bloqueio de
  atividade não-pronta, lead time e score P×I, causa obrigatória.
- **Onda 4 — Policies e papéis** por obra, incluindo o acesso
  `cliente_leitura`.
- **Onda 5 — Telas:** Restrições, Lookahead, Matriz de Prontidão,
  Last Planner, dashboards, e os diferenciais da seção 4.

---

## 7. Prompts prontos para o Claude Code

Cole um de cada vez. Espere terminar e revise antes do próximo.

### Prompt 0 — Auditoria (sem alterar nada)
```
Leia o estado atual deste projeto: migrations em database/migrations,
models em app/Models, a configuração de auth e o CLAUDE.md. Verifique se
há dados em produção (pergunte se não souber). Compare com o alvo em
docs/BRIEFING-DCF-RADAR.md e na referência em /referencia/dcf-radar.

NÃO altere nenhum arquivo. Produza um RELATÓRIO DE LACUNAS:
1. O que já existe e está alinhado.
2. Conflitos diretos (bigint vs ULID, colunas com nome diferente,
   tabelas sem tenant_id, campo "contratante" vs entidade clientes).
3. O que falta criar.
4. Riscos destrutivos caso já existam dados, com mitigação.
```

### Prompt 1 — Plano (Plan Mode)
```
Com base no relatório, entre em Plan Mode e proponha o plano da Onda 1
(fundação: tenants, users, clientes, obras, obra_user + trait/scope/
middleware), reconciliando com o que já existe. Detalhe cada migration e
arquivo. Não escreva código ainda — espere minha aprovação.
```

### Prompt 2 — Executar a fundação
```
Implemente a Onda 1 conforme aprovado, usando /referencia/dcf-radar como
forma-alvo. Ao final rode `php artisan migrate:fresh` e
`php artisan test --filter=TenantIsolationTest` e mostre o resultado.
Não prossiga para a Onda 2.
```

### Prompt 3 — Planejamento (domínio)
```
Onda 2: crie as tabelas de planejamento (disciplinas,
categorias_restricao com pilar_lean, pacotes_trabalho com parent_id
auto-aninhado, atividades, restricoes, restricao_acoes,
causas_nao_cumprimento) e seus models. Toda tabela de domínio usa o trait
BelongsToTenant e chave ULID. Siga o CLAUDE.md. Rode os testes ao final.
```

### Prompt 4 — Regras de negócio
```
Onda 3: implemente a prontidão derivada (pronta = zero restrições
bloqueantes não resolvidas), o bloqueio que impede atividade não-pronta
de ir ao plano semanal, a causa obrigatória ao marcar nao_concluido, e os
cálculos de lead time (aberta_em → resolvida_em) e score P×I. Cubra com
testes.
```

### Prompt 5 — Policies, papéis e cliente
```
Onda 4: implemente as Policies por obra a partir do papel na pivot
obra_user, incluindo o acesso cliente_leitura (somente leitura na obra).
Garanta que um usuário cliente_leitura não consegue criar nem editar nada.
```

---

## 8. Dicas de condução

- `CLAUDE.md` curto e imperativo: contrato de comportamento, não
  documentação. Atualize quando uma decisão mudar.
- Se o Claude Code ignorar uma regra, confira com `/memory` o que carregou.
- Revise cada diff. A auditoria-primeiro existe para você nunca aprovar
  uma migração destrutiva sem ver.
- Referência oficial (memória/CLAUDE.md, Plan Mode):
  https://docs.claude.com/en/docs/claude-code/overview
