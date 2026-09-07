# Levantamento Funcional Integral do DCF.eng

> **Documento gerado por investigação fresh-read do código-fonte** (Laravel 10, projeto DCF Radar), sem apoio em documentação prévia, resumos de "Ciclos" ou memória de sessões anteriores. Cada afirmação técnica é rastreável a arquivo/classe/método real. Tarefa 100% read-only — nenhum código foi alterado na produção deste levantamento.
>
> **Tagging usado no documento inteiro:**
> - `[IMPLEMENTADO]` — confirmado no código, com citação de arquivo/classe/método.
> - `[RECOMENDAÇÃO OPERACIONAL]` — sugestão de uso/processo, não imposta pelo código.
> - `[LIMITAÇÃO]` — comportamento ausente ou restrito, por design ou por lacuna.
> - `[DÍVIDA TÉCNICA]` — decisão de arquitetura com custo/risco conhecido, aceito conscientemente.
> - `[BUG SUSPEITO]` — comportamento que parece incorreto e merece investigação/decisão.
> - `[DECISÃO NECESSÁRIA]` — pergunta sem resposta objetiva só no código; exige decisão de produto/negócio.
>
> Base para: manual de implantação/configuração, manual do usuário, roteiro operacional, manual por perfil, checklists de cadastro/operação, guia de interpretação de telas/cockpits/alertas, roteiro de treinamento, material comercial, copy de vendas, demonstração comercial guiada. **Este documento em si não é nenhum desses materiais** — é a base factual da qual eles serão derivados em etapas futuras.

---

## Índice

1. [O Produto em Termos Simples](#1-o-produto-em-termos-simples)
2. [Mapa de Módulos e Conexões](#2-mapa-de-módulos-e-conexões)
3. [Menu, Rotas e Permissões](#3-menu-rotas-e-permissões)
4. [Cadastros Mestres e Ordem de Implantação](#4-cadastros-mestres-e-ordem-de-implantação)
5. [Planejamento, Cronograma, Restrições e Central de Prontidão](#5-planejamento-cronograma-restrições-e-central-de-prontidão)
6. [Engenharia, GED, GRD e Take Off](#6-engenharia-ged-grd-e-take-off)
7. [Suprimentos e Industrialização em Terceiros](#7-suprimentos-e-industrialização-em-terceiros)
8. [Estoque](#8-estoque)
9. [Importações, Exportações e Relatórios do Sistema](#9-importações-exportações-e-relatórios-do-sistema)
10. [Gestão: Cockpits Executivos, Situações Gerenciais e Notificações](#10-gestão-cockpits-executivos-situações-gerenciais-e-notificações)
11. [Lições Aprendidas — Memória Organizacional](#11-lições-aprendidas--memória-organizacional)
12. [Inventário Técnico Consolidado](#12-inventário-técnico-consolidado)
13. [Rotinas Operacionais Propostas](#13-rotinas-operacionais-propostas)
14. [Matrizes de Gestão da Informação](#14-matrizes-de-gestão-da-informação)
15. [Erros Operacionais Perigosos e Funcionalidades com Limitações Reais](#15-erros-operacionais-perigosos-e-funcionalidades-com-limitações-reais)
16. [Roteiro de Teste Manual Completo](#16-roteiro-de-teste-manual-completo)
17. [Especificação de Massa de Dados de Teste Realista](#17-especificação-de-massa-de-dados-de-teste-realista)
18. [Pré-requisitos para Demonstração Comercial](#18-pré-requisitos-para-demonstração-comercial)
19. [Funcionalidade × Benefício](#19-funcionalidade--benefício)
20. [Afirmações Comerciais Seguras vs. Perigosas](#20-afirmações-comerciais-seguras-vs-perigosas)
21. [Personas](#21-personas)
22. [Glossário](#22-glossário)
23. [Lacunas que Não Podem Ser Determinadas Só pelo Código](#23-lacunas-que-não-podem-ser-determinadas-só-pelo-código)
24. [Confirmação de Rastreabilidade das Regras Críticas](#24-confirmação-de-rastreabilidade-das-regras-críticas)
25. [Checklist: "O Manual Consegue Responder Estas Perguntas?"](#25-checklist-o-manual-consegue-responder-estas-perguntas)
26. [Prontidão para os 7 Entregáveis Futuros](#26-prontidão-para-os-7-entregáveis-futuros)

---

## 1. O Produto em Termos Simples

*(Síntese autoral, não delegada — escrita a partir da leitura consolidada de todos os módulos abaixo, sem linguagem de marketing.)*

### Que problema o DCF Radar resolve

Uma obra de construção/EPC (engenharia-suprimento-construção) tem um problema estrutural: o cronograma diz o que **deveria** acontecer, mas quase nunca é o cronograma que trava a execução — é a **falta de algo que deveria já estar pronto** quando a atividade chega: um documento de engenharia ainda não liberado, um material que não chegou, uma pendência que ninguém resolveu a tempo. O Last Planner System (LPS), metodologia de referência da construção enxuta, chama isso de "restrição" — algo que impede uma atividade de ser executada, e que precisa ser removido **antes** da semana em que a atividade estava planejada, não durante.

O DCF Radar existe para dar visibilidade e disciplina a esse processo: identificar cedo o que pode travar a obra, dar um lugar único para registrar e cobrar a remoção dessas travas, e mostrar — antes da semana de execução — quais atividades estão realmente prontas para entrar no compromisso semanal da equipe.

### As áreas conectadas e a unidade central de gestão

A unidade central de gestão do sistema é a **Obra** (`Work`) — tudo o que acontece no DCF Radar acontece dentro do contexto de uma obra específica, escolhida pelo usuário ao entrar no sistema. Uma mesma empresa (tenant) pode operar várias obras simultaneamente, cada uma com seu próprio cronograma, restrições, suprimentos, estoque e documentação — sem misturar dados entre elas.

Ao redor da obra, seis áreas funcionais se conectam numa cadeia real (não apenas visual):

- **Cronograma/Planejamento** — o cronograma importado do MS Project é a espinha dorsal: toda Atividade, Restrição, Documento e Pacote de Compra se referencia a ele.
- **Restrições** — o Quadro de Restrições e o Lookahead (as próximas semanas do cronograma) são onde a equipe registra e resolve o que impede a execução. A "prontidão" de uma atividade nunca é um campo digitado — é sempre **calculada** a partir de zero restrições abertas, checklist completo e documentação liberada.
- **Engenharia/GED** — controla quais documentos de projeto (desenhos, especificações) já foram emitidos, revisados e **liberados para construção**. Um documento não liberado bloqueia a atividade que depende dele, automaticamente.
- **Suprimentos** — do Take Off (lista de materiais extraída do projeto) até o Pedido de Compra e o Recebimento físico, com alertas automáticos quando o material vai chegar depois de quando a obra precisa dele.
- **Estoque** — controla fisicamente o que entrou, foi reservado, saiu e foi aplicado em campo, sempre a partir de um livro-razão de movimentações (nunca um "saldo" solto que possa divergir da realidade).
- **Lições Aprendidas** — transforma experiência operacional (uma restrição resolvida, um atraso recorrente) em conhecimento reutilizável por outras obras da mesma empresa.

Uma camada de **Gestão** (Dashboard e 3 Cockpits executivos) fica no topo, sempre só lendo — nunca uma fonte de verdade paralela — para responder, em poucos minutos, "o que pode parar minha obra esta semana?".

### Fluxo de informação — de onde vêm os dados, o que é derivado

O sistema é alimentado principalmente por **duas entradas externas**: a importação do cronograma MS Project (que traz atividades, datas, avanço físico e horas-homem) e planilhas de Lista de Documentos/Take Off (que trazem os documentos de engenharia e materiais do projeto). A partir dessas duas entradas, quase todo o resto do sistema é construído por cadastro manual disciplinado (restrições, pedidos de compra, movimentações de estoque) e por **cálculo em tempo real** — o sistema segue à risca o princípio de nunca persistir um número que possa ficar divergente da realidade: saldo de estoque, prontidão de atividade, folga de atendimento de material, tudo é recalculado a cada consulta a partir dos fatos registrados (o "ledger").

### Decisões que o sistema apoia

- **Semanalmente**: quais atividades comprometer no Plano Semanal (só as prontas), e o que precisa ser resolvido antes da próxima semana.
- **A cada reunião de coordenação**: onde a cadeia de suprimentos ameaça o cronograma, o que a Engenharia ainda precisa liberar, o que está pendente no Estoque.
- **No dia a dia**: registrar e cobrar restrições, dar entrada/saída de material, aprovar liberação de documentos, receber pedidos de compra.
- **Na gestão executiva**: visão consolidada por obra (Cockpits) e comparação entre obras (Benchmarking).

### Colaboração exigida

O sistema só funciona bem quando várias funções alimentam dados que outras funções consomem: o Planejamento importa o cronograma que a Engenharia usa para vincular documentos e que Suprimentos usa para calcular necessidade de material; a Engenharia libera documentos que destravam a prontidão que o Encarregado usa para comprometer o Plano Semanal; o Almoxarifado registra movimentações que o Cockpit de Suprimentos usa para alertar sobre risco de atraso. Nenhuma dessas áreas opera isolada sem prejuízo às demais.

### Eventos que acontecem diária, semanal e eventualmente

- **Diário**: consulta ao Dashboard/Cockpit Executivo; registro de causas de não-cumprimento; movimentação de estoque (entrada/saída); tratamento de inconsistências de avanço.
- **Semanal**: importação de avanço do cronograma; reunião de Lookahead/Central de Prontidão; fechamento e geração da Programação Semanal; emissão do Report semanal; digest de notificações (prontidão, GED, situações gerenciais).
- **Eventual**: importação de Baseline (nova versão do cronograma); emissão de GRD; emissão de Pedido de Compra; abertura de Inventário Físico; publicação de uma Lição Aprendida; onboarding de uma obra nova.

---

## 2. Mapa de Módulos e Conexões

*(Síntese autoral consolidando os 10 levantamentos de código que compõem este documento.)*

| Módulo | Função central | Consome de | Alimenta |
|---|---|---|---|
| **Cronograma/Planejamento** | Importação MS Project, Health Check, Linha de Base, Curvas S, Lookahead, Programação Semanal | — (entrada externa) | Restrições, Report, Suprimentos (necessidade), Engenharia (vínculo de atividade) |
| **Restrições** | Quadro de Restrições, Prontidão derivada, Plano de Ação, Matriz P×I, Relatórios | Cronograma (Atividade) | Central de Prontidão, Cockpits, Situações Gerenciais |
| **Engenharia/GED** | Documento↔Revisão, Liberação para construção, GRD, Take Off | Cronograma (vínculo de atividade) | Restrições (bloqueio de prontidão), Suprimentos (Pacote↔Documento), Estoque (Material via Take Off) |
| **Suprimentos** | Mecanismo legado (Fluxo/Etapas) + cadeia formal (RP→Alocação→RC→Pedido→Recebimento), Industrialização em Terceiros | Take Off, Cronograma (necessidade) | Estoque (entrada física), Restrições (bloqueio automático), Cockpits |
| **Estoque** | Material/Local/Movimentação (ledger), Reserva/Destinação, Saída, Conciliação/Aplicação, Inventário, QR | Suprimentos (Recebimento) | Situações Gerenciais, Cockpits, Lições Aprendidas (contexto) |
| **Report Semanal** | Curvas congeladas, 8 diagnósticos, link público ao cliente | Cronograma, Restrições, Engenharia, Suprimentos (fotografia) | Cliente externo (link assinado), Dashboard |
| **Lições Aprendidas** | Candidato→Lição→Publicação→Reutilização→Reaplicação | Restrições, Suprimentos, Estoque (fatos de origem) | Lookahead/Estoque (sugestão contextual) |
| **Gestão/Cockpits** | Dashboard, Cockpit Executivo/Suprimentos/Engenharia, Benchmarking | Todos os módulos (só leitura) | Nenhum — é sempre o topo da cadeia de leitura |
| **Situações Gerenciais/Notificações** | 12 tipos de fato derivado → ciclo de comunicação (in-app/e-mail) | Suprimentos, Estoque, Engenharia, Inventário | Central de Notificações, Cockpits, digests |
| **Cadastros** | Cliente, Obra, Perfil, Fornecedor, Feriado, etc. | — (base) | Todos os módulos |
| **Admin/Cobrança** | Gestão de tenants, planos, assinatura Mercado Pago | — | Gate de acesso à plataforma inteira |

**Regra arquitetural que atravessa o sistema inteiro** (confirmada de forma consistente nos 10 levantamentos): **o fato é sempre o ledger/histórico; o estado é sempre uma pergunta feita a ele, nunca uma resposta guardada.** Prontidão de atividade, saldo de estoque, folga de atendimento de material, situação gerencial, pipeline de suprimentos — nenhum desses é uma coluna de banco. Todos são recalculados em tempo de leitura a partir de tabelas de fato (Restrição, Movimentação de Estoque, Recebimento de Pedido, etc.). Ver Capítulo 12.4 para o inventário completo desses cálculos.

---

## 3. Menu, Rotas e Permissões

# Levantamento Funcional — DCF Radar (Fresh-Read do Código-Fonte)

&gt; Metodologia: leitura direta e completa de `resources/menu/verticalMenu.json`, `app/Providers/MenuServiceProvider.php`, `routes/web.php`, `routes/auth.php`, `routes/api.php`, `app/Http/Kernel.php`, `app/Support/CatalogoFuncionalidades.php`, `app/Models/Perfil.php`, `app/Enums/Papel.php`, `app/Providers/AuthServiceProvider.php`, todas as Policies referenciadas, e inspeção pontual dos componentes Livewire (`⚡*.blade.php`) para confirmar/refutar enforcement real de permissão.

### 3.1 — Mapa de Módulos (visão do agente que investigou este domínio)

O sistema é organizado em módulos que formam uma cadeia de valor contínua: da engenharia/documentação → planejamento/cronograma → restrições/execução → suprimentos/estoque → aprendizado organizacional, com uma camada de "Gestão/Cockpits" no topo que lê (nunca escreve) de todos os outros.

| Módulo | O que faz | Como se conecta |
|---|---|---|
| **Cronograma / Planejamento (Obras)** | Importa cronograma MS Project (XML/MSPDI) com Health Check de coerência (`App\Support\HealthCheck\HealthCheckEngine`, 36 regras), mantém Linhas de Base (snapshots imutáveis por atividade), e gera Curvas S (Previsto×Tendência×Realizado) via `App\Services\CurvaAvanco`. `[IMPLEMENTADO]` rotas `radar.cronograma`/`radar.linhas-base`/`radar.curvas`. | Alimenta Restrições (toda atividade nasce daqui), Report (curvas usam os mesmos dados), Requisições de Planejamento e Estoque (`ItemSuprimento::necessidade()` lê `Atividade.inicio_planejado`). |
| **Restrições (Last Planner System)** | Núcleo do produto: Lookahead (EAP + prontidão derivada), Quadro de Restrições, Plano Semanal (com congelamento/versionamento), Matriz P×I, Causas de Não Cumprimento, Relatórios de Restrições (7 indicadores), Plano de Ação (ponte Health Check→Restrição) e Central de Prontidão (visão consolidada de bloqueios). `[IMPLEMENTADO]` seção "2. RESTRIÇÕES" do menu. | Prontidão é sempre derivada de restrições abertas (`Atividade::scopeProntas()`) — consumida por Estoque, Engenharia (documento bloqueante) e pelos 3 Cockpits gerenciais. |
| **Report (Relatórios semanais)** | Assistente de criação de Report (rascunho/emitido, dupla trava por permissão+status), curvas S congeladas ("fotografia, não vista ao vivo"), indicadores da semana anterior/próxima, link público assinado para cliente (`ClienteRelatorioPublicoController`). `[IMPLEMENTADO]` `report.relatorios`/`report.importar_avanco`. | Consome dados de Cronograma/Restrições/Engenharia/Suprimentos já congelados no momento da geração; nunca relê ao vivo depois de criado. |
| **Engenharia / GED** | Registro Mestre de Documentos (Documento≠Revisão), liberação para construção (histórico append-only `RevisaoLiberacao`), GRD (distribuição física com aceite/QR/comprovante), Take Off (LM/LI com Curva ABC). `[IMPLEMENTADO]` seção "4. ENGENHARIA". | Documento não liberado bloqueia prontidão de Atividade (`scopeProntas()`) e Pacote de Suprimentos (`ItemSuprimento::documentosEngenharia()`); Take Off alimenta Requisição de Planejamento. |
| **Suprimentos** | Cadeia formal: Take Off → Requisição de Planejamento (RP) → Alocação a Pacote de Compra (`ItemSuprimento`) → Requisição de Compra (RC) → Pedido/Ordem de Compra → Recebimento físico, com fluxo legado paralelo (`FluxoSuprimento`/`SuprimentoScheduler`, alertas de prazo 21/10 dias). `[IMPLEMENTADO]` `planejamento.requisicoes` + `suprimentos.mapa`. | Alimenta Estoque (entrada física a partir de Recebimento) e é lido por 2 dos 3 Cockpits gerenciais e pelo motor de Situações Gerenciais (alertas). |
| **Estoque** | Fundação física (Material/Local/Entrada), Destinação Planejada + Reserva, Saída/retirada de campo, Conciliação/Aplicação real, Industrialização em Terceiros (custódia + genealogia), Inventário físico com Ajuste formal, Identificação por QR Code. `[IMPLEMENTADO]` única página `radar.estoque` com múltiplas abas, cada uma com seu próprio slug de permissão. | Saldo é sempre derivado do ledger (`MovimentacaoEstoque`) — nunca uma coluna de saldo. Conecta de volta ao cronograma via `ItemSuprimento::necessidade()`. |
| **Industrialização em Terceiros** | Sub-módulo do Estoque (não item de menu próprio): Ordem de Industrialização, Remessa (envio/retorno), Produção, Consumo de matéria-prima, Entrega — tudo modelado como movimentações de estoque entre Local Próprio e Local Terceiro. `[IMPLEMENTADO]` slug `estoque.industrializacao`. | Vive dentro da tela Estoque; referencia Documento de Engenharia (revisão usada na fabricação) e Pacote de Suprimentos. |
| **Lições Aprendidas (Memória Operacional)** | Converte experiência operacional (restrições resolvidas, atrasos, desvios) em conhecimento corporativo versionado: Candidato→análise humana→Lição→Publicação→Reutilização contextual→Reaplicação avaliada. `[IMPLEMENTADO]` `gestao.licoes-aprendidas`, ESCOPO_TENANT. | Lê fatos de Restrições/Suprimentos/Estoque; nunca escreve neles. Reutilização contextual aparece dentro de Lookahead e Estoque via link. |
| **Gestão / Cockpits** | Camada 100% leitura, somente composição de serviços já existentes: Dashboard, Cockpit Executivo, Cockpit de Suprimentos, Cockpit de Engenharia, Benchmarking entre Obras, Minhas Obras. `[IMPLEMENTADO]` menu topo + seção "1. OBRAS". | Nunca é fonte de verdade — sempre agrega `SituacoesGerenciaisQuery`/`CoberturaMaterialAtividadeQuery`/etc. de outros módulos. |
| **Cadastros** | Catálogos tenant-scoped: Clientes, Obras, Tipos de Restrição, Itens de Prontidão, Convite por E-mail, Fornecedores, Feriados, Tipos de Fluxo de Suprimento, Status de Documento. `[IMPLEMENTADO]` submenu "CONFIGURAÇÕES → Cadastros". | Base para todos os outros módulos. |
| **Admin (Plataforma)** | Área `/admin`, fora do escopo de qualquer tenant: gestão de Contas (Tenants), Usuários cross-tenant, Planos, Avisos, impersonation auditada. `[IMPLEMENTADO]` gate `acessar-admin-plataforma`. | Não usa `BelongsToTenant`/perfis de tenant — modelo de autorização totalmente separado. |
| **Cobrança (billing)** | Autoatendimento via Mercado Pago (`app.empresa.assinatura`), 3 métodos de pagamento, trial automático de 7 dias, inadimplência automática. `[IMPLEMENTADO]` middleware `assinatura.ativa`. | Gate de acesso à plataforma inteira; gerenciado também manualmente pelo Admin. |

### 3.2 — Mapa de Menu

Fonte: `resources/menu/verticalMenu.json` (lido 100%) + lógica de renderização em `resources/views/layouts/sections/menu/verticalMenu.blade.php` + `app/Support/CatalogoFuncionalidades.php` (`usuarioPodeVer()`/`algumSubitemVisivel()`).

**Mecanismo de visibilidade** `[IMPLEMENTADO]` (`verticalMenu.blade.php:32-36`):
- Item com `gate` → checado via `Gate::allows($menu-&gt;gate)`.
- Item com `funcionalidade` → checado via `CatalogoFuncionalidades::usuarioPodeVer($menu-&gt;funcionalidade)`, que resolve `ver` na obra ativa (ESCOPO_OBRA) ou em qualquer obra do tenant (ESCOPO_TENANT). **Fora de contexto de obra, item ESCOPO_OBRA fica sempre visível** (`CatalogoFuncionalidades.php:242-244`).
- Item com `submenu` → some por completo se todos os filhos tiverem `funcionalidade` invisível (`algumSubitemVisivel`).
- Item sem `gate` nem `funcionalidade` (ex.: "Início", "Minhas Obras") → **sempre visível**, sem gating nenhum.

#### Árvore de menu do usuário (`/app/**`)

```
Início                                   /app/home                          [sem gate]
Dashboard                                /app/radar/dashboard               dashboard.gerencial
Cockpit Executivo                        /app/radar/cockpit                 gestao.cockpit          (bx-radar)
Cockpit de Suprimentos                   /app/radar/cockpit-suprimentos     gestao.suprimentos      (bx-package)
Cockpit de Engenharia                    /app/radar/cockpit-engenharia      gestao.engenharia       (bx-compass)

── 1. OBRAS ──
Minhas Obras                             /app/gestao/minhas-obras           [sem gate — sempre visível]
Benchmarking                             /app/gestao/benchmarking           gestao.benchmarking
Lições Aprendidas                        /app/gestao/licoes-aprendidas      gestao.licoes-aprendidas  (bx-bulb)
Importar Cronograma                      /app/radar/cronograma              obras.importar_cronograma
Linhas de Base                           /app/radar/linhas-base             obras.linhas_base
Curvas S                                 /app/radar/curvas                  obras.curvas

── 2. RESTRIÇÕES ──
Lookahead Lean                           /app/radar/lookahead               restricoes.lookahead
Quadro de Restrições                     /app/radar/restricoes              restricoes.quadro
Plano Semanal                            /app/radar/plano-semanal           restricoes.plano_semanal
Minhas Programações                      /app/radar/minhas-programacoes     restricoes.minhas_programacoes
Causas de Não Cumprimento                /app/radar/causas                  restricoes.causas
Matriz P×I                               /app/radar/matriz                  restricoes.matriz
Relatórios de Restrições                 /app/radar/relatorios-restricoes   restricoes.relatorios
Plano de Ação                            /app/radar/plano-acao              restricoes.plano_acao
Central de Prontidão                     /app/radar/central-prontidao       restricoes.central_prontidao
Inconsistências de Avanço                /app/radar/inconsistencias-avanco  restricoes.lookahead    (REUSA slug!)

── 3. REPORT ──
Relatórios                               /app/radar/relatorios              report.relatorios
Importar Avanço                          /app/radar/relatorios/importar-avanco  report.importar_avanco

── 4. ENGENHARIA ──
Lista de Documentos                      /app/engenharia/pacotes            engenharia.pacotes
GRDs                                     /app/engenharia/grds               engenharia.pacotes      (REUSA slug!)
Take Off                                 /app/engenharia/take-off           engenharia.pacotes      (REUSA slug!)

── 5. PLANEJAMENTO ──
Requisições do Planejamento              /app/planejamento/requisicoes      planejamento.requisicoes

── 6. SUPRIMENTOS ──
Mapa de Suprimentos                      /app/radar/suprimentos             suprimentos.mapa

── 7. ESTOQUE ──
Estoque                                  /app/radar/estoque                 estoque.movimentacao

── CONFIGURAÇÕES ──
Cadastros ▾ (some se todos filhos ocultos)
  Clientes                               /app/cadastros/clientes            cadastros.clientes
  Obras                                  /app/cadastros/obras               cadastros.obras
  Tipos de Restrição                     /app/cadastros/categorias-restricao cadastros.categorias_restricao
  Itens de Prontidão                     /app/cadastros/itens-prontidao     cadastros.itens_prontidao
  Convite por E-mail                     /app/cadastros/convite-config      cadastros.convite_config
  Fornecedores                           /app/cadastros/fornecedores        cadastros.fornecedores
  Feriados                               /app/cadastros/feriados            cadastros.feriados
  Tipos de Fluxo (Suprimentos)           /app/cadastros/fluxos-suprimento   cadastros.fluxos_suprimento
  Status de Documento                    /app/cadastros/status-documentos   cadastros.status_documentos

Perfis de Acesso                         /app/perfis-acesso                 [gate: gerenciar-perfis-acesso]
Dados da Empresa                         /app/empresa                      [gate: gerenciar-empresa-ativa]

── ADMINISTRAÇÃO ── (cabeçalho + tudo abaixo: gate acessar-admin-plataforma)
Painel do Proprietário ▾                 /admin
  Contas (Tenants)                       /admin/tenants
  Usuários                               /admin/usuarios
  Planos                                 /admin/planos
  Avisos aos Usuários                    /admin/avisos
```

`[IMPLEMENTADO]` **Menu de `/admin`** (`resources/views/layouts/sections/menu/adminVerticalMenu.blade.php:10-16`): não é um segundo JSON — filtra o **mesmo** `$menuData[0]-&gt;menu` compartilhado pelo `MenuServiceProvider`, pegando só o item com `gate === 'acessar-admin-plataforma'` que tem `submenu`, e **achata** o submenu em links de 1º nível. Editar `verticalMenu.json` é o único lugar para adicionar página nova em `/admin`.

**Achados de inconsistência no menu:**
- `[BUG SUSPEITO]` `engenharia.pacotes` é reaproveitado como `funcionalidade` para 3 itens de menu distintos (Lista de Documentos, GRDs, Take Off). Não existe forma de dar acesso só a GRDs sem dar acesso também à Lista de Documentos e ao Take Off. `[DECISÃO NECESSÁRIA]` se o produto amadurecer a ponto de exigir perfis que vejam só GRD.
- `[BUG SUSPEITO]` "Inconsistências de Avanço" reaproveita `restricoes.lookahead` como `funcionalidade` — não há como dar acesso a Inconsistências sem também dar acesso ao Lookahead completo.
- `[IMPLEMENTADO]` (comportamento correto) `gestao.cockpit`/`gestao.suprimentos`/`gestao.engenharia` são os **únicos 3 slugs do catálogo cujo `ver` não é concedido por padrão** — por isso o item de menu só aparece para perfis GerentePlanejamento+.

### 3.3 — Mapa de Rotas e Screens Inventory

Fonte: `routes/web.php` (443 linhas, lido 100%) + `routes/auth.php` + `routes/api.php` + `app/Http/Kernel.php`.

#### Rotas de negócio (autenticadas, `/app/**`)

Grupo base: `middleware(['auth','verified','assinatura.ativa'])-&gt;prefix('app')`.

| Rota (name) | URL | View / Componente Livewire | Middleware extra | Slug/permissão exigida |
|---|---|---|---|---|
| `app.home` | `/app/home` | `app.index` (Blade puro, template "Academy Dashboard" não customizado — ver Achado) | — | nenhuma |
| `notificacoes.index` | `/app/notificacoes` | `app.notificacoes.index` → `pages::notificacoes.index` | — | nenhuma (por usuário) |
| `notificacoes.abrir` | `/app/notificacoes/{notification}/abrir` | closure (deep-link) | — | ownership manual |
| `app.onboarding` | `/app/onboarding` | `app.onboarding` | — | nenhuma |
| `app.empresa.show` | `/app/empresa` | `app.empresa.show` → `pages::empresa.perfil` | — | Gate inline `podeGerenciarTenant()` |
| `app.empresa.trocar` (POST) | `/app/empresa/trocar/{tenant}` | — (ação) | — | `auth()-&gt;user()-&gt;tenants()-&gt;where(...)-&gt;exists()` |
| `app.empresa.assinatura` | `/app/empresa/assinatura` | `app.empresa.assinatura` → `pages::empresa.assinatura` | — | Gate inline `podeGerenciarTenant()` |
| `gestao.perfis-acesso` | `/app/perfis-acesso` | `app.gestao.perfis-acesso` → `pages::gestao.perfis-acesso` | — | Gate `gerenciar-perfis-acesso` |
| `atividade-anexos.download` | `/app/atividade-anexos/{anexo}/download` | Controller `AtividadeAnexoController@download` | — | resolução manual |
| `atividade-anexos.destroy` (DELETE) | `/app/atividade-anexos/{anexo}` | Controller `AtividadeAnexoController@destroy` | — | idem |
| `licao-evidencias.download` | `/app/licao-evidencias/{evidencia}/download` | Controller `LicaoAprendidaEvidenciaController@download` | — | `LicaoAprendidaPolicy::view()` |
| `licao-evidencias.destroy` (DELETE) | `/app/licao-evidencias/{evidencia}` | Controller `@destroy` | — | `LicaoAprendidaPolicy::update()` |
| `documentos-engenharia.revisoes.download` | `/app/documentos-engenharia/revisoes/{revisao}/download` | Controller `DocumentoEngenhariaRevisaoController@download` | — | resolução manual |
| `cadastros.clientes.index` | `/app/cadastros/clientes` | `app.clientes.index` → `pages::clientes.index` | — | `cadastros.clientes\|ver` |
| `cadastros.obras.index` | `/app/cadastros/obras` | `app.obras.index` → `pages::obras.index` | — | `cadastros.obras\|ver` |
| `cadastros.categorias-restricao` | `/app/cadastros/categorias-restricao` | `pages::cadastros.categorias-restricao` | — | `cadastros.categorias_restricao\|ver` |
| `cadastros.itens-prontidao` | `/app/cadastros/itens-prontidao` | `pages::cadastros.itens-prontidao` | — | `cadastros.itens_prontidao\|ver` |
| `cadastros.convite-config` | `/app/cadastros/convite-config` | `pages::cadastros.convite-config` | — | `cadastros.convite_config\|ver` |
| `cadastros.fornecedores` | `/app/cadastros/fornecedores` | `pages::cadastros.fornecedores` | — | `cadastros.fornecedores\|ver` |
| `cadastros.feriados` | `/app/cadastros/feriados` | `pages::cadastros.feriados` | — | `cadastros.feriados\|ver` |
| `cadastros.fluxos-suprimento` | `/app/cadastros/fluxos-suprimento` | `pages::cadastros.fluxos-suprimento` | — | `cadastros.fluxos_suprimento\|ver` |
| `cadastros.status-documentos` | `/app/cadastros/status-documentos` | `pages::cadastros.status-documentos` | — | `cadastros.status_documentos\|ver` |
| `gestao.minhas-obras` | `/app/gestao/minhas-obras` | `pages::gestao.minhas-obras` | — | nenhuma no menu; `WorkPolicy` interna |
| `gestao.obra.show` | `/app/gestao/obras/{obra}` | `pages::gestao.obra-detalhe` | — | `WorkPolicy::view()` |
| `gestao.benchmarking` | `/app/gestao/benchmarking` | `pages::gestao.benchmarking-obras` | — | `gestao.benchmarking\|ver` |
| `gestao.licoes-aprendidas` | `/app/gestao/licoes-aprendidas` | `pages::gestao.licoes-aprendidas` | — | `gestao.licoes-aprendidas\|ver` |
| `radar.entrar` | `/app/radar/entrar/{obraId}` | closure (só redirect) | — | `temAcessoAObra()` |
| `radar.dashboard` | `/app/radar/dashboard` | `pages::radar.dashboard` | `obra.context` | `dashboard.gerencial\|ver` (menu apenas — **não enforçado em `mount()`**) |
| `radar.lookahead` | `/app/radar/lookahead` | `pages::radar.lookahead` | `obra.context` | `restricoes.lookahead` |
| `radar.restricoes` | `/app/radar/restricoes` | `pages::radar.restricoes` | `obra.context` | `restricoes.quadro` via `RestricaoPolicy` |
| `radar.plano-semanal` | `/app/radar/plano-semanal` | `pages::radar.plano-semanal` | `obra.context` | `restricoes.plano_semanal` |
| `radar.programacoes` | `/app/radar/minhas-programacoes` | `pages::radar.programacoes` | `obra.context` | `restricoes.minhas_programacoes` |
| `radar.causas` | `/app/radar/causas` | `pages::radar.causas` | `obra.context` | `restricoes.causas` (`ver`; **`criar`/`editar` do slug são mortos**) |
| `radar.matriz` | `/app/radar/matriz` | `pages::radar.matriz` | `obra.context` | `restricoes.matriz` (`ver`; **`editar` é morto**) |
| `radar.relatorios-restricoes` | `/app/radar/relatorios-restricoes` | `pages::radar.relatorios-restricoes` | `obra.context` | `restricoes.relatorios\|ver` |
| `radar.cronograma` | `/app/radar/cronograma` | `pages::radar.cronograma` | `obra.context` | `obras.importar_cronograma` |
| `radar.curvas` | `/app/radar/curvas` | `pages::radar.curvas` | `obra.context` | `obras.curvas` (**`editar`/`excluir` NÃO checados no componente**) |
| `radar.linhas-base` | `/app/radar/linhas-base` | `pages::radar.linhas-base` | `obra.context` | `obras.linhas_base` via `PacoteTrabalhoPolicy` |
| `radar.relatorios` | `/app/radar/relatorios` | `pages::radar.relatorios` | `obra.context` | `report.relatorios` |
| `radar.relatorios.novo` | `/app/radar/relatorios/novo` | `pages::radar.relatorio-novo` | `obra.context` | `report.relatorios\|criar` |
| `radar.relatorios.importar-avanco` | `/app/radar/relatorios/importar-avanco` | `pages::radar.relatorio-importar-avanco` | `obra.context` | `report.importar_avanco` (**`criar`/`editar`/`excluir` NÃO checados**) |
| `radar.relatorios.show` | `/app/radar/relatorios/{report}` | `pages::radar.relatorio-detalhe` | `obra.context` | `ReportPolicy::view()` |
| `radar.importacoes.show` | `/app/radar/importacoes/{importacao}` | `pages::radar.importacao-detalhe` | `obra.context` | `CronogramaImportacaoPolicy::view()` |
| `radar.suprimentos` | `/app/radar/suprimentos` | `pages::radar.suprimentos` | `obra.context` | `suprimentos.mapa` |
| `radar.estoque` | `/app/radar/estoque` | `pages::radar.estoque` | `obra.context` | `estoque.movimentacao` (+ sub-slugs por aba) |
| `radar.plano-acao` | `/app/radar/plano-acao` | `pages::radar.plano-acao` | `obra.context` | `restricoes.plano_acao` via `PlanoAcaoPolicy` |
| `radar.central-prontidao` | `/app/radar/central-prontidao` | `pages::radar.central-prontidao` | `obra.context` | `restricoes.central_prontidao\|ver` |
| `radar.cockpit` | `/app/radar/cockpit` | `pages::radar.cockpit` | `obra.context` | `gestao.cockpit\|ver` (enforçado em `mount()`) |
| `radar.cockpit-suprimentos` | `/app/radar/cockpit-suprimentos` | `pages::radar.cockpit-suprimentos` | `obra.context` | `gestao.suprimentos\|ver` (enforçado) |
| `radar.cockpit-engenharia` | `/app/radar/cockpit-engenharia` | `pages::radar.cockpit-engenharia` | `obra.context` | `gestao.engenharia\|ver` (enforçado) |
| `radar.inconsistencias-avanco` | `/app/radar/inconsistencias-avanco` | `pages::radar.inconsistencias-avanco` | `obra.context` | `InconsistenciaAvancoPolicy` (reusa `restricoes.lookahead`) |
| `planejamento.requisicoes` | `/app/planejamento/requisicoes` | `pages::planejamento.requisicoes-planejamento` | `obra.context` | `planejamento.requisicoes` via `RequisicaoPlanejamentoPolicy` |
| `engenharia.pacotes` | `/app/engenharia/pacotes` | `pages::engenharia.documentos-engenharia` | — (seletor de obra próprio) | `engenharia.pacotes`, ESCOPO_TENANT |
| `engenharia.grds` | `/app/engenharia/grds` | `pages::engenharia.grds` | — | `engenharia.pacotes` (reuso) |
| `engenharia.take-off` | `/app/engenharia/take-off` | `pages::engenharia.take-off` | — | `engenharia.pacotes` (reuso) |
| `profile.show` | `/profile` | `profile.show` (Jetstream) | fora do prefixo `app` | nenhuma além de autenticado |

#### Rotas de Administração da Plataforma (`/admin/**`)

Grupo: `middleware(['auth','verified','platform.admin'])-&gt;prefix('admin')-&gt;name('admin.')`.

| Rota (name) | URL | View / Componente | Slug/permissão |
|---|---|---|---|
| `admin.dashboard` | `/admin` | `pages::admin.dashboard` | Gate `acessar-admin-plataforma` |
| `admin.tenants.index` | `/admin/tenants` | `pages::admin.tenants.index` | idem |
| `admin.tenants.show` | `/admin/tenants/{tenant}` | `pages::admin.tenants.show` (`withTrashed()`) | idem |
| `admin.tenants.impersonar` (POST) | `/admin/tenants/{tenant}/impersonar` | — (`ImpersonationContext::start()`) | idem |
| `admin.usuarios.index` | `/admin/usuarios` | `pages::admin.usuarios.index` | idem |
| `admin.planos.index` | `/admin/planos` | `pages::admin.planos.index` | idem |
| `admin.avisos.index` | `/admin/avisos` | `pages::admin.avisos.index` | idem |
| `admin.impersonar.parar` (POST) | `/admin/impersonar/parar` | — (`ImpersonationContext::stop()`) | idem |

`[LIMITAÇÃO]` `/admin` não tem RBAC interno — qualquer `is_platform_admin` tem acesso irrestrito a todas as sub-telas administrativas.

#### Rotas públicas (sem login) e webhooks

| Rota (name) | URL | Controller | Middleware | Observação |
|---|---|---|---|---|
| `/` (sem name) | `/` | closure → `pages::site.index` | nenhum | landing page pública |
| `cliente.relatorio.publico` | `/cliente/relatorio/{report}` | `ClienteRelatorioPublicoController@show` | `signed` | link assinado de 30 dias, escopo a 1 Report emitido |
| `publico.grd-verificacao` | `/verificar/grd/{token}` | `GrdVerificacaoPublicaController@show` | nenhum | token persistido (não assinado) |
| `webhooks.mercadopago` (POST) | `/webhooks/mercadopago` | `MercadoPagoWebhookController@handle` | nenhum (CSRF excepcionado) | autenticidade via HMAC `x-signature` |

`[IMPLEMENTADO]` `routes/api.php` tem só `GET /user` (via `auth:sanctum`) — vestígio do scaffold padrão, sem uso funcional real. Produto é 100% server-rendered (Livewire), não expõe API REST própria.

#### Rotas órfãs, mortas ou não linkadas do menu

| Rota | Situação | Classificação |
|---|---|---|
| **`dashboard`** (`GET /dashboard` → `view('dashboard')`) | `resources/views/dashboard.blade.php` **não existe**. Nenhuma referência a `route('dashboard')` em todo `resources/`. | **`[BUG SUSPEITO]`** — rota morta e quebrada, resquício do scaffold Jetstream, substituída por `app.home`. Quebra com 500 se alguém digitar `/dashboard` manualmente. |
| `radar.entrar`, `radar.relatorios.novo`, `radar.relatorios.show`, `radar.importacoes.show`, `gestao.obra.show`, `admin.tenants.show`, `app.empresa.assinatura` | Não aparecem no menu, mas alcançáveis via links internos (cards, botões de detalhe, drill-down). | `[IMPLEMENTADO]` — corretamente fora da navegação principal, não são órfãs. |
| `atividade-anexos.*`, `licao-evidencias.*`, `documentos-engenharia.revisoes.download` | Endpoints de download/delete chamados via `href`/AJAX de dentro de outras telas. | `[IMPLEMENTADO]` — intencional. |
| `notificacoes.index`, `notificacoes.abrir`, `profile.show` | Alcançáveis via navbar (sino, dropdown de usuário). | `[IMPLEMENTADO]`. |
| **Rotas duplicadas** | Nenhuma encontrada (mesmo path/method para handlers diferentes). | `[IMPLEMENTADO]` (achado negativo). |

### 3.4 — Permissions Inventory (tabela completa)

Fonte: `app/Support/CatalogoFuncionalidades.php::todas()` (38 slugs, lido 100%) cruzado com `app/Models/Perfil.php::REGRAS_ESCRITA`.

**Regra estrutural** `[IMPLEMENTADO]`: para **todo** slug, `ver` é concedido incondicionalmente a qualquer um dos 5 perfis padrão — **exceto** para `gestao.cockpit`, `gestao.suprimentos`, `gestao.engenharia`, que exigem nível ≥ GerentePlanejamento.

Legenda: **A** = Admin(5) · **GP** = GerentePlanejamento(4) · **E** = Engenheiro(3) · **En** = Encarregado(2) · **—** = ninguém tem essa ação · **✓(todos)** = liberado a todos os 5 perfis.

| # | Slug | Nome | Escopo | ver | criar | editar | excluir | Módulo |
|---|---|---|---|---|---|---|---|---|
| 1 | `dashboard.gerencial` | Dashboard | OBRA | ✓(todos) | — | — | — | Gestão |
| 2 | `gestao.cockpit` | Cockpit Executivo | OBRA | **GP** | — | — | — | Gestão |
| 3 | `gestao.suprimentos` | Cockpit de Suprimentos | OBRA | **GP** | — | — | — | Gestão |
| 4 | `gestao.engenharia` | Cockpit de Engenharia | OBRA | **GP** | — | — | — | Gestão |
| 5 | `obras.minhas_obras` | Minhas Obras | OBRA | ✓(todos) | — | **GP** | **A** | Cronograma |
| 6 | `gestao.benchmarking` | Benchmarking | TENANT | ✓(todos) | — | — | — | Gestão |
| 7 | `gestao.licoes-aprendidas` | Lições Aprendidas | TENANT | ✓(todos) | **En** | **E** | **GP** | Lições Aprendidas |
| 8 | `obras.importar_cronograma` | Importar Cronograma | OBRA | ✓(todos) | **GP** | **GP** | **GP** | Cronograma |
| 9 | `obras.linhas_base` | Linhas de Base | OBRA | ✓(todos) | **GP** | **GP** | **GP** | Cronograma |
| 10 | `obras.curvas` | Curvas S | OBRA | ✓(todos) | — | **GP** ⚠ | **GP** | Cronograma |
| 11 | `restricoes.lookahead` | Lookahead Lean | OBRA | ✓(todos) | **E** | **En** | **GP** | Restrições |
| 12 | `restricoes.quadro` | Quadro de Restrições | OBRA | ✓(todos) | **En** | **E** | **GP** | Restrições |
| 13 | `restricoes.plano_semanal` | Plano Semanal | OBRA | ✓(todos) | — | **En** | — | Restrições |
| 14 | `restricoes.minhas_programacoes` | Minhas Programações | OBRA | ✓(todos) | — | **En** | — | Restrições |
| 15 | `restricoes.causas` | Causas de Não Cumprimento | OBRA | ✓(todos) | **En** ⚠ | **En** ⚠ | — | Restrições |
| 16 | `restricoes.matriz` | Matriz P×I | OBRA | ✓(todos) | — | **E** ⚠ | — | Restrições |
| 17 | `restricoes.relatorios` | Relatórios de Restrições | OBRA | ✓(todos) | — | — | — | Restrições |
| 18 | `restricoes.plano_acao` | Plano de Ação | OBRA | ✓(todos) | **En** | **E** | **GP** | Restrições |
| 19 | `restricoes.central_prontidao` | Central de Prontidão | OBRA | ✓(todos) | — | — | — | Restrições |
| 20 | `report.relatorios` | Relatórios | OBRA | ✓(todos) | **GP** | **GP** | **GP** | Report |
| 21 | `report.importar_avanco` | Importar Avanço | OBRA | ✓(todos) | **GP** ⚠ | **GP** ⚠ | **GP** ⚠ | Report |
| 22 | `suprimentos.mapa` | Mapa de Suprimentos | OBRA | ✓(todos) | **En** | **E** | **GP** | Suprimentos |
| 23 | `planejamento.requisicoes` | Requisições do Planejamento | OBRA | ✓(todos) | — | **GP** | — | Suprimentos |
| 24 | `estoque.movimentacao` | Estoque | OBRA | ✓(todos) | **En** | **E** | **GP** | Estoque |
| 25 | `estoque.reserva` | Planejamento / Reservas | OBRA | ✓(todos) | — | **GP** | — | Estoque |
| 26 | `estoque.conciliacao` | Conciliação / Aplicação | OBRA | ✓(todos) | **En** | **En** | **GP** | Estoque |
| 27 | `estoque.industrializacao` | Industrialização | OBRA | ✓(todos) | **En** | **E** | **GP** | Industrialização |
| 28 | `estoque.inventario` | Inventário | OBRA | ✓(todos) | **En** | **E** | **GP** | Estoque |
| 29 | `cadastros.clientes` | Clientes | TENANT | ✓(todos) | **A** | **A** | **A** | Cadastros |
| 30 | `cadastros.obras` | Obras (cadastro) | TENANT | ✓(todos) | **A** | **A** | **A** | Cadastros |
| 31 | `cadastros.categorias_restricao` | Tipos de Restrição | TENANT | ✓(todos) | **A** | **A** | **A** | Cadastros |
| 32 | `cadastros.itens_prontidao` | Itens de Prontidão | TENANT | ✓(todos) | **A** | **A** | **A** | Cadastros |
| 33 | `cadastros.convite_config` | Convite por E-mail | TENANT | ✓(todos) | **A** | **A** | **A** | Cadastros |
| 34 | `cadastros.fornecedores` | Fornecedores | TENANT | ✓(todos) | **A** | **A** | **A** | Cadastros |
| 35 | `cadastros.feriados` | Feriados | TENANT | ✓(todos) | **A** | **A** | **A** | Cadastros |
| 36 | `cadastros.fluxos_suprimento` | Tipos de Fluxo (Suprimentos) | TENANT | ✓(todos) | **A** | **A** | **A** | Cadastros |
| 37 | `cadastros.status_documentos` | Status de Documento | TENANT | ✓(todos) | **A** | **A** | **A** | Cadastros |
| 38 | `engenharia.pacotes` | Lista de Documentos (+GRD, Take Off) | TENANT | ✓(todos) | **A** | **A** | **A** | Engenharia/GED |

⚠ = ação com nível mínimo definido no catálogo, mas **sem enforcement real no código da página**.

**`[BUG SUSPEITO]` 4 slugs com toggles de escrita "fantasma"** (a tela "Perfis de Acesso" mostra checkboxes que não têm efeito real):
1. **`obras.curvas` (editar/excluir)** — `⚡curvas.blade.php::salvarAjuste()` grava `CurvaAjuste::updateOrCreate(...)` **sem nenhuma checagem de permissão**. Qualquer usuário com acesso à obra edita um ajuste de Curva S, independentemente do perfil.
2. **`restricoes.causas` (criar/editar)** — `⚡causas.blade.php` é 100% leitura; os toggles não correspondem a nenhuma ação existente.
3. **`restricoes.matriz` (editar)** — idem, tela 100% leitura.
4. **`report.importar_avanco` (criar/editar/excluir)** — `⚡relatorio-importar-avanco.blade.php::analisar()`/`confirmar()` (que despacha `ImportarCronogramaJob` e grava avanço) não checam permissão de perfil — só guardas de estado.

`[LIMITAÇÃO]` (aceita, não bug): `restricoes.relatorios`, `restricoes.central_prontidao`, `dashboard.gerencial`, `gestao.benchmarking` não têm entradas de escrita — páginas genuinamente só-leitura por design.

`[IMPLEMENTADO]` (positivo, contraste): `restricoes.quadro`, `restricoes.lookahead`, `obras.linhas_base`, `obras.importar_cronograma`, `report.relatorios`, `restricoes.plano_acao`, `planejamento.requisicoes`, todos os `estoque.*`, `gestao.licoes-aprendidas` **são** enforçados de verdade via Policy dedicada ou chamada inline `temPermissaoNaObra()`.

`[DÍVIDA TÉCNICA]` `estoque.reserva`/`conciliacao`/`industrializacao`/`inventario` nunca aparecem no menu (só `estoque.movimentacao` é listado) — são checados só dentro dos métodos do componente único, por aba. Usuário só descobre que não pode agir ao tentar (mensagem amigável), não por a aba estar escondida.

### 3.5 — Papéis/Perfis Operacionais (guia didático)

#### 🔑 Administrador (nível 5)
**Pode:** absolutamente tudo, incluindo o único perfil com acesso a **Cadastros** e à **Lista de Documentos/GRD/Take Off**, e o único que exclui uma obra inteira.
**Não pode:** nada dentro do próprio tenant — é o teto.
**Quando usar:** dono da construtora, diretor de operações, configuração inicial do sistema.

#### 📋 Gerente de Planejamento (nível 4)
**Pode:** importar/gerenciar Cronograma e Linhas de Base; excluir Restrições/Atividades/itens do Plano de Ação; gerar/gerenciar Report e Importar Avanço; único nível (abaixo de Admin) que edita Requisição do Planejamento e Reserva de Estoque; excluir movimentações críticas de Estoque/Conciliação/Industrialização/Inventário; único nível que enxerga os 3 Cockpits Executivos; editar (não excluir) uma obra.
**Não pode:** cadastrar/editar Clientes, Feriados, Fornecedores, Tipos de Restrição/Fluxo/Status, GED.
**Quando usar:** engenheiro de planejamento sênior, coordenador de obra.

#### 🛠️ Engenheiro (nível 3)
**Pode:** criar itens do Lookahead, editar Quadro de Restrições, Plano de Ação, Mapa de Suprimentos, Estoque (movimentação/industrialização/inventário), Lições Aprendidas.
**Não pode:** **excluir nada** (nenhum slug dá `excluir` a este nível); ver os Cockpits; editar Requisição do Planejamento/Reserva; mexer em Cadastros/GED.
**Quando usar:** engenheiro de campo/projeto.

#### 👷 Encarregado (nível 2)
**Pode:** cadastrar restrições, marcar prontidão, comprometer atividades no Plano Semanal, criar itens do Plano de Ação, registrar entrada/saída/conciliação básica de Estoque, criar Ordens de Industrialização, abrir Inventários, registrar causas, criar Pacotes no Mapa de Suprimentos, criar rascunhos de Lições Aprendidas.
**Não pode, de forma alguma:** **excluir absolutamente nada** (invariante testada explicitamente — `test_encarregado_nao_tem_nenhuma_permissao_de_excluir`); criar itens do Lookahead; editar Quadro de Restrições/Matriz/Requisições/Estoque avançado; ver Cockpits.
**Quando usar:** encarregado de obra, mestre de obra, líder de equipe.

#### 👁️ Cliente (Leitura) (nível 1)
**Pode:** só visualizar tudo que qualquer usuário vinculado à obra vê.
**Não pode:** nada que mude dado; não vê os Cockpits.
**Quando usar:** cliente contratante, fiscalização externa.

#### Tabela-resumo

| Perfil | Pode excluir? | Vê os Cockpits? | Mexe em Cadastros/GED? | Cria no Lookahead? |
|---|---|---|---|---|
| Admin | Sim, tudo | Sim | Sim | Sim |
| Gerente de Planejamento | Sim (quase tudo, exceto Cadastros/GED) | Sim | **Não** | Sim |
| Engenheiro | **Não, nunca** | **Não** | Não | Sim |
| Encarregado | **Não, nunca** | **Não** | Não | **Não** |
| Cliente (Leitura) | **Não, nunca** | **Não** | Não | **Não** |

### 3.6 — Resumo dos principais achados desta seção

1. **`[BUG SUSPEITO]`** Rota morta e quebrada `GET /dashboard` — view inexistente, 500 garantido.
2. **`[BUG SUSPEITO]`** 4 slugs de permissão sem enforcement real (item 3.4).
3. **`[BUG SUSPEITO]`** Reuso de `engenharia.pacotes` para 3 telas e `restricoes.lookahead` para Inconsistências — impede acesso granular.
4. **`[LIMITAÇÃO]`** `/admin` sem RBAC interno.
5. **`[DÍVIDA TÉCNICA]`** `resources/views/app/index.blade.php` (`/app/home`, primeira tela após login) ainda contém texto de template não traduzido/customizado ("Academy Dashboard").

---

## 4. Cadastros Mestres e Ordem de Implantação

*(Investigação fresh-read de `database/migrations/*`, Models, Actions, `app/Support/Onboarding/OnboardingChecklist.php`, `app/Http/Middleware/RequireObraContext.php`.)*

### 4.1 Cadastros mestres

#### Tenant (Construtora)
Raiz do isolamento multitenant. `[IMPLEMENTADO: app/Models/Tenant.php]`. Campo obrigatório: `name`. Nasce via `/register` ou `php artisan app:seed-owner`. **Efeitos automáticos na criação**: `Perfil::seedPadrao()` (5 perfis) e `criarTrialAutomatico()` (assinatura de 7 dias, se houver plano trial configurado). `[LIMITAÇÃO]` `SoftDeletes` não cascateia automaticamente — só `forceDelete()`. Depende dele: tudo.

#### User (usuário)
`[IMPLEMENTADO: app/Models/User.php]`. Obrigatórios: `tenant_id`, `first_name`, `last_name`, `email` (unique global), `password`. **`[LIMITAÇÃO]` única entidade de domínio (com `assinaturas`/`assinatura_faturas`) sem `BelongsToTenant`** — toda query de tela do tenant precisa filtrar `tenant_id` manualmente. `ativo=false` bloqueia login via middleware global, nunca deleção física.

#### Client (Cliente — dono da obra)
`[IMPLEMENTADO: database/migrations/2026_05_14_224747_create_clients_table.php]`. Obrigatório: `name`. **Primeiro passo formal do onboarding do tenant** (`cliente_cadastrado`). `[LIMITAÇÃO/RISCO]` sem `SoftDeletes`; `works.client_id` é `cascadeOnDelete()` — apagar um Cliente apaga TODAS as obras dele em cascata, sem guard de domínio. `Work.client_id` é `NOT NULL` — impossível criar obra sem Cliente já existente.

#### Work (Obra)
`[IMPLEMENTADO: database/migrations/2026_05_18_001053_create_works_table.php]`. Obrigatórios: `tenant_id`, `client_id`. **Segundo passo obrigatório do onboarding** (`obra_cadastrada`). Efeito automático: o criador do tenant é automaticamente vinculado como Admin em toda obra nova (`Work::booted()::created`). Validação de plano (`Tenant::limiteObras()`) bloqueia criação acima do limite do plano.

#### Perfil / PerfilPermissao
`[IMPLEMENTADO: app/Models/Perfil.php]`. Nasce sozinho na criação do tenant (5 perfis com `slug_padrao` estável). Vínculo por obra via `obra_user.perfil_id` — o mesmo usuário pode ter perfis diferentes em obras diferentes.

#### CategoriaRestricao (Tipo de Restrição / Pilar Lean)
`[IMPLEMENTADO]` Obrigatórios: `tenant_id`, `nome`, `pilar_lean`. **Terceiro passo do onboarding, mas OPCIONAL** (`categoria_restricao_cadastrada`). `Restricao.categoria_id` é nullable — sistema funciona sem nenhuma categoria, só perde granularidade de relatório.

#### Disciplina
`[LIMITAÇÃO IMPORTANTE]` Não tem tela própria de cadastro manual — criada exclusivamente via `firstOrCreate()` dentro dos importadores (cronograma, Take Off, LD). A lista de Disciplinas só existe **depois** de uma importação com o campo populado.

#### PacoteTrabalho (EAP)
`[IMPLEMENTADO]` Sempre via importação de cronograma (tarefas-resumo/projeto do XML) — sem cadastro manual de EAP na UI padrão.

#### FrenteTrabalho
**`[LIMITAÇÃO CRÍTICA]`** Não existe NENHUMA tela de cadastro manual — só `firstOrCreate()` em `MsProjectImporter::aplicar()` a partir de `Texto22`. Se o cronograma importado não popular esse campo, a obra fica **sem nenhuma Frente de Trabalho disponível** — Destinação Planejada por Frente e a exibição de Frente na Saída de Estoque ficam inutilizáveis. `[DECISÃO NECESSÁRIA]` garantir que o template do MS Project preencha `Texto22` antes da 1ª importação, se a obra for usar Estoque com controle por Frente.

#### Entregavel, EquipeResponsavel, Personalizado1-5 (Texto20/24/26-30)
`[IMPLEMENTADO]` 6 tabelas de lookup obra-scoped, todas criadas exclusivamente via `firstOrCreate()` na importação de cronograma — nenhuma tela manual encontrada.

#### Fornecedor
`[IMPLEMENTADO]` Tela dedicada `cadastros.fornecedores`. Obrigatórios: `tenant_id`, `obra_id`, `nome`. `nome`/`cnpj` propagam para `PedidoCompra.fornecedor_nome_snapshot`/`_cnpj_snapshot` **no momento da emissão** (congelado). `PedidoCompra.fornecedor_id` é `NOT NULL` — impossível criar Pedido sem Fornecedor já cadastrado.

#### Material / UnidadeMedida / FamiliaMaterial (catálogo de estoque)
`[IMPLEMENTADO]` **UnidadeMedida precisa existir ANTES de criar um Material** (`Material.unidade_medida_id NOT NULL`). `FamiliaMaterial` é opcional. Cadastrados dentro do próprio módulo de Estoque, não em `cadastros.*`. `MaterialObserver::deleting()` bloqueia exclusão se houver histórico — recomenda inativar (`ativo=false`).

#### LocalEstoque
`[IMPLEMENTADO]` Obrigatórios: `tenant_id`, `obra_id`, `nome`, `tipo`. `fornecedor_id` obrigatório só quando `tipo=Terceiro`. `tipo`/`fornecedor_id` ficam **congelados** assim que o Local tem qualquer movimentação.

#### Destinatario (GRD)
`[IMPLEMENTADO]` Criado inline dentro da própria tela de GRD, não em `cadastros.*`. Editar não afeta GRD já Emitida (dados congelados em snapshot na emissão).

#### Plano / Assinatura
`[IMPLEMENTADO]` Só admin da plataforma cadastra. `null` = ilimitado em `limiteObras()`/`limiteUsuarios()` — "sem plano = sem restrição" é o fallback consistente em todo o sistema.

### 4.2 Ordem correta para implantar uma obra nova

#### Fase 0 — Conta e tenant (uma vez por empresa)
| # | Etapa | Quem | Observação |
|---|---|---|---|
| 0.1 | Cadastro do tenant (`/register` ou `app:seed-owner`) | Dono da construtora | Dispara `Perfil::seedPadrao()` + trial automático |
| 0.2 | Confirmar e-mail | Usuário | Sem isso, algumas rotas exigem `verified` |

#### Fase 1 — Cadastros de tenant (obrigatório antes da 1ª obra)
| # | Etapa | Obrigatório? | Consequência de pular |
|---|---|---|---|
| 1.1 | Cadastrar 1º Cliente | **Sim** `[IMPLEMENTADO]` | `Work.client_id` FK `NOT NULL` — fisicamente impossível criar obra |
| 1.2 | Cadastrar 1ª Obra | **Sim** `[IMPLEMENTADO]` | Sem obra, `RequireObraContext` redireciona sempre para "Minhas Obras" |
| 1.3 | Configurar Tipos de Restrição (Pilares Lean) | **Não** `[IMPLEMENTADO: obrigatorio=false]` | Restrições ficam sem categoria (aceito, `categoria_id` nullable) |

`[RECOMENDAÇÃO OPERACIONAL]` Fazer 1.3 antes de operar Restrições no dia a dia — trocar categoria depois é possível, mas atrasa o hábito de classificar por Pilar Lean.

#### Fase 2 — Base técnica da obra
| # | Etapa | Quem | Por que vem antes | Erro comum |
|---|---|---|---|---|
| 2.1 | **Importar Cronograma ou cadastrar Atividade manual** | GerentePlanejamento | **Passo obrigatório único do checklist de obra** (`atividades_cadastradas`) — `RequireObraContext` bloqueia TODA outra rota do Radar (exceto `radar.cronograma`) até existir ≥1 atividade | Importar XML sem `Texto20-30`/`PredecessorLink` populados perde toda a classificação |
| 2.2 | Revisar Health Check/Score da importação | Mesmo | Nunca bloqueia, mas alerta sobre inconsistências reais | Ignorar avisos Críticos/Altos sistematicamente |
| 2.3 | Configurar Itens de Prontidão (checklist adicional) | GP/Admin | **Opcional**, resolvido por UNION em tempo de leitura (não backfill) — pode ser configurado a qualquer momento | Nenhum — é retroativo |
| 2.4 | Cadastrar a 1ª Restrição | Encarregado+ | Opcional no checklist, mas "coração do LPS"; exige Atividade já existente | Achar que "prontidão" é um campo — é sempre derivada |

#### Fase 3 — Equipe e acesso
| # | Etapa | Observação |
|---|---|---|
| 3.1 | Convidar usuários para a obra | Exige `perfil_id` já existente — como os 5 padrão nascem automáticos, nunca bloqueado tecnicamente |
| 3.2 (opcional) | Criar Perfis customizados | Só criador do tenant (`podeGerenciarTenant()`); os 5 padrão já cobrem o fluxo básico |

#### Fase 4 — Cadastros operacionais complementares (não bloqueiam o Radar, mas bloqueiam módulos específicos)
| # | Etapa | Bloqueia o quê se faltar | Ordem interna |
|---|---|---|---|
| 4.1 | Fornecedores | Emitir Pedido de Compra; Local de Estoque tipo Terceiro | Antes do 1º Pedido |
| 4.2 | UnidadeMedida | Cadastrar qualquer Material | Antes de 4.4 |
| 4.3 | FamiliaMaterial (opcional) | Nada estrutural | Indiferente |
| 4.4 | Material | Toda a cadeia de Estoque | Depois de 4.2 |
| 4.5 | LocalEstoque | Registrar Entrada/Saída física | Antes da 1ª Entrada |
| 4.6 | PacoteEngenharia | Cadastrar `DocumentoEngenharia` | Antes de importar LD |
| 4.7 | Importar Take Off sobre Revisão liberada | Requisição do Planejamento | Depois de 4.6 |
| 4.8 | Destinatario (GRD) | Emitir GRD com destinatário | Pode ser inline na emissão |

`[DECISÃO NECESSÁRIA]` Se a obra vai usar Suprimentos/Estoque/Engenharia desde o início, planejar a Fase 4 **em paralelo** com a Fase 2.

### 4.3 Dependências entre cadastros (DAG conceitual)

Legenda: 🔴 dependência técnica obrigatória (FK `NOT NULL`/bloqueio real) · 🟡 dependência funcional recomendada (sistema deixa fazer, mas fica incompleto) · 🟢 informação opcional.

```
Tenant
 ├─🔴 User / Client
 │    └─🔴 Work (client_id NOT NULL)
 │         ├─🔴 obra_user (User×Work×Perfil)
 │         ├─🔴 Atividade — PORTA DE ENTRADA DO RADAR
 │         │    ├─🟡 PacoteTrabalho / Disciplina / FrenteTrabalho / Entregavel / EquipeResponsavel / Personalizado1-5
 │         │    │      (todos só via importação de cronograma)
 │         │    ├─🔴 Restricao (atividade_id NOT NULL)
 │         │    │    └─🟢 CategoriaRestricao
 │         │    ├─🟡 ItemProntidao
 │         │    └─🟡 ItemSuprimento (Pacote de Compra)
 │         │         └─🟡 Fornecedor (🔴 obrigatório no PedidoCompra)
 │         ├─🔴 PacoteEngenharia → DocumentoEngenharia → Revisão → ListaEngenharia → ItemTakeOff
 │         │         ├─🟡 Material (🔴 UnidadeMedida obrigatória nele)
 │         │         └─🔴 RequisicaoPlanejamentoItem → AlocacaoRequisicaoPacote
 │         │                    → RequisicaoCompraItem → PedidoCompraItem → RecebimentoPedido
 │         │                         └─🟡 MovimentacaoEstoque (Entrada, exige Material)
 │         │                              └─🔴 LocalEstoque
 │         ├─🔴 Grd → GrdItem / GrdDestinatario
 │         │         └─🟡 Destinatario
 │         └─🔴 PedidoCompra (fornecedor_id NOT NULL, requisicao_compra_id só se Emitida/Concluída)
 └─🟢 Plano/Assinatura (nunca bloqueia cadastro de domínio)
```

### 4.4 Matriz "o que manter atualizado" — cadastros mestres

| Informação | Responsável típico | Frequência | Onde atualizar | O que fica errado se desatualizada |
|---|---|---|---|---|
| Cliente (razão social/CNPJ) | Administrativo/Comercial | Uma vez, raramente muda | Cadastros → Clientes | Relatórios exibem nome/CNPJ desatualizado |
| Obra (linha de base ao vivo, `budget_total`) | Gerente de Planejamento | Início do projeto | Cadastros → Obras / importação | Confusão entre campo "ao vivo" e a Linha de Base oficial |
| Disciplina/Etapa/Frente/Entregável/Equipe/Personalizado1-5 | Planejamento/Engenharia (via template MS Project) | A cada reimportação | O arquivo .xml (sem UI manual) | Se nunca populados, filtros ficam vazios permanentemente |
| CategoriaRestricao (Pilares Lean) | Gerente de Planejamento | Baixa | Cadastros → Tipos de Restrição | Restrições sem categoria não aparecem em relatórios por Pilar |
| Perfis de Acesso | Criador do tenant | Baixa | Perfis de Acesso | Permissão insuficiente ou excessiva |
| Fornecedor | Suprimentos/Compras | Sempre que mudar | Cadastros → Fornecedores | Nome/CNPJ errado fica congelado em Pedidos já emitidos |
| UnidadeMedida/FamiliaMaterial/Material | Suprimentos, com aval de Engenharia | Alta no início, depois estável | Tela Estoque → Materiais | Reclassificação retroativa não é limpa |
| LocalEstoque | Almoxarife/Encarregado | Baixa, crítica antes do 1º uso | Tela Estoque → Locais | `tipo`/`fornecedor` congelam após o 1º uso |
| Destinatario (GRD) | Engenharia/Documentação | Sempre que muda | Inline na tela de GRD | Só afeta GRDs futuras (já emitida é fotografia) |

---

## 5. Planejamento, Cronograma, Restrições e Central de Prontidão

*(Investigação fresh-read de `app/Imports/MsProjectImporter.php`, `app/Support/HealthCheck/*`, `app/Models/{Atividade,Restricao,ProgramacaoSemanal,LinhaBase,AtividadeSnapshot*}.php`, `App\Services\{CurvaAvanco,DetectorInconsistenciasAvanco}`, `App\Support\CentralProntidao\CentralProntidaoQuery`.)*

### 5.1 Importação de Cronograma MS Project

**Fluxo**: upload `.xml`/MSPDI na tela Cronograma → `analisar()` (100% leitura, monta `PlanoImportacao` em memória, casa tarefas por `external_uid`) → Health Check roda sobre o plano → prévia de criar/atualizar/arquivar → `confirmar()` dispara `ImportarCronogramaJob` (assíncrono, com polling).

**Baseline vs. Avanço vs. Ambos** (`App\Enums\TipoCronogramaImportacao`):

| Tipo | Grava | Cria/atualiza/arquiva EAP? | Onde |
|---|---|---|---|
| **Baseline** | Série `Previsto` de HH | **Sim** | `radar.cronograma` |
| **Avanço** | `Realizado`/`Tendencia`, progresso (`percentual_concluido`, `real_inicio/termino`) | **Nunca** — só `update()` em atividades já casadas | `radar.relatorios.importar-avanco` |
| **Ambos** | As duas coisas | Sim | (legado, default histórico) |

`[RECOMENDAÇÃO OPERACIONAL]` Cronograma mudou de estrutura → importar como **Baseline**. Só o avanço físico mudou → importar como **Avanço**. Atividade nova que aparece só na importação de Avanço é **ignorada silenciosamente** (a prévia mostra isso, mas é fácil não perceber).

**Reconciliação**: sempre por `external_uid` + `obra_id`. Atividade que "some" numa reimportação **nunca é apagada** — vira `fora_do_cronograma = true` (arquivada), preservando restrições vinculadas. Atividades manuais (`origem=manual`) nunca são tocadas pela reimportação.

**Health Check** (`App\Support\HealthCheck\HealthCheckEngine`, **36 regras**, nenhuma bloqueante): Datas (7), Avanço Físico (4), HH (6), Duração (2), Caminho Crítico (1), Marcos (2), Baseline (2), Estrutura (5 — STRUCT-001..005, incluindo ciclo lógico via Tarjan), Lógica (4 — LOGIC-005/008/009/010), Folgas/Slack (3 — SLACK-001/002/005). Numa importação **Baseline**, só as regras de natureza `Planejamento` rodam (as de `Execucao` dependem de dado real). Severidades: Crítico/Alto/Médio/Baixo/Informativo — nenhuma bloqueia a confirmação.

**Score de Saúde** (`App\Support\HealthCheck\Score\ScoreCalculator`): 4 camadas (severidade→peso bruto→peso máximo→impacto do finding→soma clamp[0,100]). Faixas: 90-100 Excelente · 80-89 Bom · 70-79 Atenção · 60-69 Necessita atenção · 0-59 Crítico. Exposto na tela de detalhe da importação (`radar.importacoes.show`), com Evolução do Score comparando contra a importação anterior do mesmo tipo.

`[RECOMENDAÇÃO OPERACIONAL]` **Crítico** (datas invertidas, ciclo lógico): pare e corrija na origem antes de confirmar. **Alto**: revisar antes de seguir. **Informativo**: raramente exige ação.

### 5.2 Atividades, WBS, PacoteTrabalho, Disciplina

Hierarquia: Tenant → Cliente → Obra → PacoteTrabalho (EAP auto-aninhada por `parent_id`) → Atividade → Restricao → RestricaoAcao.

**Status** (`StatusAtividade`): `Planejado → Comprometido → EmExecucao → Concluido | NaoConcluido`. `AtividadeObserver::updating()` grava/limpa `concluido_em` automaticamente a cada transição de status — **única fonte de verdade** de "quando foi concluída" (nunca `real_termino`, que só o importador grava, nem `updated_at`). O mesmo Observer **bloqueia** a transição para `Comprometido` se `! estaPronta()` — trava no nível do model, não só na UI. `NaoConcluido` exige Causa registrada.

### 5.3 Linha de Base, Snapshots, Fotografia F/O/P

**Linha de Base** (`LinhaBase`): rótulo nomeado apontando para uma `cronograma_importacao_id` Baseline específica.

**Fotografia F** (`AtividadeSnapshot`): 1 linha por atividade por importação — o que o **arquivo** declarou (nunca relido da Atividade ao vivo depois).

**Fotografia O** (`AtividadeSnapshotOperacional`/`_Restricao`/`_Prontidao`): captura, antes de cada importação Avanço/Ambos, `status`/`fora_do_cronograma`/`pronta` naquele instante — "o que a plataforma sabia antes desta importação". Só Avanço/Ambos gravam (Baseline pura nunca grava).

**Fotografia P** (`AtividadeSnapshotProgramacao`): para atividade que iniciou/concluiu nesta importação, resolve qual versão da Programação Semanal era vigente naquele instante (`ProgramacaoSemanal::vigenteEm()`).

`[RECOMENDAÇÃO OPERACIONAL]` As três fotografias alimentam o **Detector de Inconsistências de Avanço** — nunca alteram Restrição/Prontidão/Atividade/Programação Semanal (zero autocorreção, garantia testada explicitamente — mesmo com atividade chegando a 100%).

### 5.4 Lookahead

Filtros: `fonteData` (baseline/tendência), `janelaDias` (30/60/90/0), busca, classificações (Etapa/Frente/Faturamento Direto/Entregável/Equipe/Personalizado1-5). Seletor independente de Linha de Base e de importação de Tendência (filtrada só entre importações Avanço/Ambos).

**Curva S no popup de detalhe**: Previsto usa a Baseline selecionada **no popup**; Realizado sempre vem do `cronograma_importacao_id` do **último Report emitido** (nunca a importação de avanço mais recente "ao vivo" — filosofia "Report é fotografia"). Indicador (farol): favorável (Realizado≥Previsto), desfavorável, neutro (sem Realizado ainda).

`[RECOMENDAÇÃO OPERACIONAL]` Farol neutro/desfavorável sem Report emitido recente pode mascarar atraso real ainda não "fotografado".

### 5.5 Programação Semanal (Plano Semanal)

**Status**: `Aberta → Fechada`. Duas travas distintas: `semanaEstaCongelada` (automática, semana já é passado) vs. `semanaEstaFechada` (explícita, via "Gerar Programação" — abre exceção para marcar concluído mesmo congelada).

`ProgramacaoSemanal::ativaPara()` resolve a versão mais recente (fluxo ao vivo); `vigenteEm()` resolve qual versão valia num **instante histórico** (só usado pela Fotografia P). Revisões (`versao`/`revisao_de_id`) só a partir de uma versão Fechada e mais recente — nasce Aberta com datas/HH **recapturados ao vivo**; a original nunca muda.

`[RECOMENDAÇÃO OPERACIONAL]` Feche a programação formalmente ao comprometer o plano da semana — preserva histórico auditável e habilita revisões rastreáveis.

### 5.6 Curvas S

`App\Services\CurvaAvanco::calcular()` — `resolverImportacaoId()` garante que Previsto só resolve de Baseline/Ambos, Realizado/Tendência só de Avanço/Ambos. Ajustes manuais (`CurvaAjuste`) escopados pelos mesmos filtros de classificação usados no cálculo — nunca vazam entre curva geral e curva filtrada.

### 5.7 Detector de Inconsistências de Avanço

`App\Services\DetectorInconsistenciasAvanco` — roda dentro da mesma transação de `aplicar()`, só para Avanço/Ambos, lendo só F+O+P **da própria importação** (nunca importações anteriores/estado ao vivo).

**8 tipos** (`TipoInconsistenciaAvanco`): Início/Conclusão × ComRestriçãoPendente/ComProntidãoPendente/ForaDaProgramação/SemProgramação. Severidade Crítica reservada a Conclusão (sinal mais forte).

**Tratamento**: `Aberta → Tratada` (sem reabertura), `UPDATE ... WHERE status='aberta'` atômico, justificativa obrigatória. **Nunca toca** Restricao/Prontidao/Atividade.

`[RECOMENDAÇÃO OPERACIONAL]` Tratar não impede a MESMA pendência reaparecer como nova inconsistência numa importação futura — a raiz precisa ser resolvida separadamente no Quadro de Restrições/Prontidão.

### 5.8 Plano de Ação (Health Check → PlanoAcao → Restrição)

Fluxo: finding do Mapa de Ações → "Criar Ação" (`PlanoAcao::criarDeFinding()`, congela título/recomendação, extrai `uids_referencia`) → opcionalmente "Transformar em Restrição" (`transformarEmRestricoes()`, revalida tudo no servidor, no máx. 1 Restrição por atividade, `bloqueante=false` por padrão, **nunca cria `RestricaoAcao`**). Zero sincronização de ciclo de vida depois — resolver/reabrir o PlanoAcao nunca propaga para a Restrição.

`PlanoAcaoReconciliador` compara `uids_referencia` entre importações e classifica: Resolvido / Persistente / Agravado / Alterado (nunca resolve sozinho, sempre exige revisão humana).

`[RECOMENDAÇÃO OPERACIONAL]` Use Plano de Ação para problemas estruturais do cronograma que precisam de acompanhamento entre reimportações; transforme em Restrição só quando realmente **impede a execução** de uma atividade específica.

### 5.9 Restrições — ciclo de vida e guia operacional

**Status** (`StatusRestricao`): `Aberta → EmTratamento/AguardandoTerceiros → Resolvida`. Todos os 3 primeiros contam como "em aberto" para bloqueio de prontidão.

**3 origens rastreáveis** (colunas dedicadas, nunca coexistem): Manual, Derivada de Suprimento (`origem_suprimento_item_id`/`origem_cadeia_suprimento_id`), Derivada de Plano de Ação (`origem_plano_acao_id`).

**Ciclo**: criar (`salvarRestricao()`, multi-seleção de atividades) → `marcarEmTratamento`/`marcarAguardandoTerceiros` → `resolver()` (exige texto, cria `RestricaoAcao`) → `reabrirRestricao()` (idem). `RestricaoPolicy`: o próprio responsável sempre pode `update`/`resolver`/`reabrir`, independente de ter `editar`.

**Impacto na prontidão**: `Atividade::scopeProntas()` — zero restrição bloqueante aberta + checklist 100% + nenhum documento vinculado não liberado. Restrição não-bloqueante nunca impede prontidão, só aparece como atenção contextual.

**Relatório de Restrições — 7+ indicadores**: por Responsável, por Período (ancorado em `prazo_limite`, toggle para `aberta_em`), **PPC por semana** (`ppcPorSemana()` — comprometidas do Plano Semanal × concluídas no prazo, `concluido_em ≤ semana_fim`), Atrasadas, Tempo Médio de Resolução (geral e por categoria, só sobre `Resolvida`), Prontidão por Disciplina, Por Pilar Lean/Categoria, Matriz P×I (`risco = probabilidade×impacto`, Alto ≥50).

`[RECOMENDAÇÃO OPERACIONAL — guia de reunião semanal]` Ordem sugerida: (1) PPC por semana; (2) Atrasadas — priorizar ação imediata; (3) Tempo médio por categoria — identificar gargalos sistêmicos; (4) Matriz P×I — focar energia nas de maior risco combinado.

### 5.10 Central de Prontidão / Radar

**Princípio**: não é uma segunda fonte de prontidão — `pronta` sempre vem de `scopeProntas()`; PlanoAcao/Suprimento/Engenharia entram só como contexto para "Atenção".

**4 estados, ordem de precedência** (`match(true)`): **Concluída** (1ª) → **Não pronta** (2ª, `!$pronta`) → **Atenção** (3ª, pronta mas com alerta contextual: restrição não-bloqueante, PlanoAcao aberto, Suprimento em risco, Engenharia atrasada via Suprimento) → **Pronta** (default).

`[IMPORTANTE]` "Não pronta" ≠ "Atenção": Não pronta **bloqueia** comprometimento; Atenção **permite**, mas com risco visível.

**Filtros/agrupamento**: horizonte (15/30/60/0 dias, filtro SQL direto), pacote/disciplina/frente/responsável/busca, agrupamento raso por Pacote/EAP. Ordenação por status usa rank **diferente** da precedência de classificação: `NaoPronta(1) < Atencao(2) < Pronta(3) < Concluida(4)`.

`[RECOMENDAÇÃO OPERACIONAL — guia de reunião semanal]`: (1) resumo geral (30 dias); (2) ordenar por status, olhar "Não pronta" primeiro — coluna de motivo já diz o porquê; (3) depois "Atenção" — pode comprometer, mas risco merece menção; (4) agrupar por Pacote identifica concentração de problema numa frente.

`[LIMITAÇÃO]` Horizonte filtra por `inicio_planejado` — atividade com início distante nunca aparece mesmo com restrição crítica há muito aberta (coberto por outras telas, sem filtro de horizonte).

---

## 6. Engenharia, GED, GRD e Take Off

*(Investigação fresh-read de `app/Models/{DocumentoEngenharia,DocumentoEngenhariaRevisao,RevisaoLiberacao,Grd*,ListaEngenharia,ItemTakeOff}.php`, `app/Imports/{DocumentoEngenhariaImporter,TakeOffImporter}.php`, `app/Support/{Grd,TakeOff,Engenharia}/*`.)*

### 6.1 Documentos de Engenharia (GED)

**Hierarquia**: `PacoteEngenharia` (agrupador opcional) → `DocumentoEngenharia` (identificado por `codigo` na obra) → `DocumentoEngenhariaRevisao` (N por documento) → eventos filhos: `RevisaoLiberacao`, `ListaEngenharia`/`ItemTakeOff`, `GrdItem`.

**Revisão vigente ≠ Liberação para construção — a distinção mais importante do módulo:**

| Conceito | Responde | Fonte |
|---|---|---|
| Revisão vigente | "Qual governa o Documento agora?" | `revisaoVigente()`/`latestRevisao()` — `ORDER BY data_emissao DESC, created_at DESC, id DESC` |
| Liberação para construção | "A vigente pode ser usada para construir?" | `estaLiberadaParaConstrucao()` |

`[IMPLEMENTADO]` `estaLiberadoParaConstrucao() = revisaoVigente()?->estaLiberadaParaConstrucao()`. **Consequência**: R1 liberada + R2 recém-nascida (nunca liberada) = Documento fica NÃO liberado, mesmo com R1 liberada intacta no histórico — protege contra usar revisão antiga só porque foi a última liberada.

`[BUG SUSPEITO — já corrigido, lição arquitetural]` `ofMany()` sobre `data_emissao` foi **rejeitado deliberadamente**: quando todas as revisões têm `data_emissao=NULL`, `MAX()` de um grupo 100% nulo é `NULL` e o JOIN de volta nunca casa — retornaria zero resultados. Por isso é um `HasOne` simples com `ORDER BY` (MySQL ordena `NULL` por último em `DESC`).

**Histórico de Liberação** (`RevisaoLiberacao`) é **append-only** — liberar/revogar é sempre uma linha nova (`ultimaLiberacao()` via `ofMany` por `created_at`/`id`, **nunca por `ocorrido_em`**, que é retroativável).

**Reprogramação de data prevista** (`DocumentoEngenhariaReprogramacao`) — histórico append-only. `[RECOMENDAÇÃO OPERACIONAL]` A planilha de LD **nunca** reescreve `data_planejada` de um documento que já teve emissão ou reprogramação — só alimenta a estimativa inicial.

**Vínculo com Atividades**: pivô N:N real (`DocumentoEngenhariaAtividade`), sempre por `atividade_id` — sobrevive a reimportação. Bloqueia vincular atividade arquivada, exceto se o vínculo já existir.

**Conexão com prontidão**: `Atividade::scopeProntas()` bloqueia se houver documento vinculado "não liberado". `[LIMITAÇÃO deliberada]` Atividade **sem** nenhum documento vinculado nunca é bloqueada por essa cláusula. Bloqueio é **binário** (4/5 liberados ainda conta "bloqueada") — granularidade rica resolvida sem tocar essa regra, numa camada gerencial paralela (seção 6.4).

**Conexão com Suprimentos**: `ItemSuprimento::documentosEngenharia()` — "este Pacote de Compra depende de documento não liberado?", manualmente curada.

**Importação de Lista de Documentos (LD)**: aba fixa `"LD"`, colunas A-E+G-H. Fluxo em 3 fases (`lerLinhas`/`analisar`/`aplicar`) + Passo 0 de escolha explícita ('novos'/'atualização') que filtra a prévia sem alterar o importador. Só **acrescenta** revisão quando o texto mudou (nunca edita/apaga revisão existente). `[LIMITAÇÃO]` sem proteção de corrida para criação de Disciplina nova (diferente do TakeOffImporter, que já tem).

**Dashboard**: totais/concluídos/aguardando/atrasados, curva de emissões (previsto×realizado), gráfico por disciplina/status, aging de atraso, top 5 reprogramados. Cards clicáveis filtram a lista sem duplicar contagem.

### 6.2 GRD (Guia de Remessa de Documentos)

**Ciclo**: `Rascunho → Emitida` (sem "Cancelada"). `numero` só atribuído na emissão (lock em `Work`). Imutabilidade estrutural via Observer (bloqueia delete incondicionalmente quando Emitida).

**Matriz item×destinatário**: `GrdItem` (revisão **exata**, fixa — nunca a vigente resolvida dinamicamente) + `GrdDestinatario` (snapshot congelado) + `GrdDistribuicao` (o fato atômico "entregue", só combinações efetivamente marcadas viram linha).

**Emissão** (`EmitirGrd`): lock em `Work`+na própria GRD, valida ≥1 item/destinatário/distribuição, **cada item precisa ser a revisão vigente E liberada** (senão exceção citando o documento), congela snapshots, atribui número sequencial.

**Recolhimento** (`GrdRecolhimento`, append-only): `Recolhido` nunca supera a quantidade entregue (soma acumulada); `NaoLocalizado` **nunca reduz** a quantidade pendente (é histórico de tentativas, limitado à pendência **no instante da tentativa**, não à soma acumulada). Estado derivado sempre pelo **último evento por ordem de registro** (`created_at DESC`), nunca por `ocorrido_em`.

**Aceite/Assinatura com QR** (`GrdAceiteEntrega`): não é assinatura ICP-Brasil — manuscrita via canvas ou aceite simples. No máximo 1 aceite ativo por destinatário (garantido por índice `UNIQUE` sobre coluna `STORED GENERATED`). QR usa `token` (48 chars, **nunca signed URL** — precisa sobreviver anos, mesmo com rotação de `APP_KEY`). Endpoint público sem login. `[LIMITAÇÃO deliberada]` página pública nunca mostra `motivo_invalidacao` (sensível) nem outros itens da GRD entregues a outros destinatários.

**Comprovantes PDF**: comprovante de Entrega é sempre 1 por destinatário (não por item — "assim como um recibo físico"); comprovante de Recolhimento é sempre de 1 evento específico. Ambos 100% a partir de snapshots congelados.

**Detector de cópias obsoletas/candidatos**: `DetectorCopiasObsoletasGrd`/`CandidatosNovaEntregaGrd` — 100% derivado, nunca persistido. Entregar R2 **nunca** recolhe automaticamente a pendência de R1.

**Alertas internos + digest**: Alerta A (revisão nova + cópias antigas pendentes), Alerta B (revisão liberada + candidatos a nova entrega) — event-driven, idempotência por UUIDv5 determinístico atribuído ao `notification->id` (PRIMARY KEY, nunca duplica). Digest semanal segunda 08:00, só `database`+`broadcast` (nunca e-mail/WhatsApp no digest — isso é papel dos Alertas imediatos).

### 6.3 Take Off (LM/LI)

**Hierarquia corrigida**: Documento → Revisão → `ListaEngenharia` (código obrigatório, `tipo` na lista inteira) → `ItemTakeOff`. Congelamento: enquanto a revisão for vigente, Lista aceita criar/editar mas **nunca é excluível** (nem vigente); Item aceita criar/editar/excluir/reimportar. Assim que revisão nova nasce, tudo da anterior congela para sempre.

**Referência de RP**: item referenciado por qualquer `RequisicaoPlanejamentoItem` (Rascunho ou Emitida) não pode ser excluído — Emitida bloqueia permanentemente.

**Consolidação e Curva ABC**: só listas de revisão **vigente** entram na consolidação. Curva ABC agrupa por `UnidadeMedida` **antes** de classificar (nunca soma metros+quilos+unidades); classe decidida pelo acumulado **antes** de somar a fatia do item (evita item dominante virar classe C).

**Associação com Material (Estoque)**: `ItemTakeOff.material_id` opcional, manual, imutável condicional — primeira associação sempre livre; trocar já preenchido é bloqueado por Pedido Emitido na cadeia OU Pacote com `DestinacaoPlanejadaMaterial` (congelamento por Pacote inteiro, política conservadora).

### 6.4 Inteligência de Engenharia (Ciclo 22 — camada gerencial de leitura)

`InteligenciaEngenhariaQuery::porObra()` compõe, sem reimplementar nenhuma regra:
- **`ProntidaoDocumentalAtividadeQuery`** — granularidade rica sobre o bloqueio binário: `EstadoProntidaoEngenharia` (`Liberada`/`Parcial`/`Bloqueada`/`InformacaoInsuficiente`), diferente e complementar a `scopeProntas()`.
- **`GrdGerencialQuery`** — cópias obsoletas pendentes + `GrdAguardandoAceite` (fato novo do Ciclo 22.3).
- **`IndustrializacaoDocumentalQuery`** — Produto industrializado com revisão diferente da vigente, **sempre fato neutro, nunca invalidação automática** (não existe regra de Qualidade no domínio para isso).
- **`SuprimentoDocumentalQuery`** — Pacotes bloqueados por Documento, via a mesma N:N já existente (`ItemSuprimento::documentosEngenharia()`).

`[LIMITAÇÃO/DÍVIDA]` Camada só de leitura — não persiste, não gera Notification diretamente (integração com o motor de Situações Gerenciais do Ciclo 21 foi uma decisão deliberada de escopo, "Opção C").

`[BUG SUSPEITO — corrigido 3x no projeto, mesma classe]` Consumir `motivoLiberacao()`/`estaLiberadaParaConstrucao()` sem eager-load de `latestRevisao.ultimaLiberacao` causa N+1/`LazyLoadingViolationException` — já corrigido em `CentralProntidaoQuery`, `SituacoesGerenciaisQuery::documentoBloqueante()` e `SuprimentoDocumentalQuery`. Qualquer novo consumidor precisa garantir esse eager-load.

---

## 7. Suprimentos e Industrialização em Terceiros

*(Investigação fresh-read de `app/Models/{ItemSuprimento,FluxoSuprimento,RequisicaoPlanejamento*,AlocacaoRequisicaoPacote,RequisicaoCompra*,PedidoCompra*,RecebimentoPedido,OrdemIndustrializacao,ProdutoIndustrializado*}.php`, `App\Services\SuprimentoScheduler`, `App\Support\Suprimentos\AlertaCadeiaSuprimento`.)*

### 7.1 Dois mecanismos coexistindo no mesmo `ItemSuprimento`

`ItemSuprimento` ("Pacote de Compra") preserva 100% do schema/model do mecanismo legado (13/07) — **nenhum rename destrutivo**. Hoje, um mesmo Pacote pode ter simultaneamente:
- **Mecanismo legado**: `fluxo_suprimento_id` + `ItemSuprimentoEtapa` (instância por Pacote, snapshot imutável de estrutura, 1 processo implícito por Pacote).
- **Cadeia formal (Ciclo 19)**: N `RequisicaoCompra` formais, cada uma com sua própria progressão de etapas.

**Os dois nunca se sincronizam entre si** — decisão de produto explícita.

**Mecanismo legado**: `App\Services\SuprimentoScheduler::congelarPrevisto()` (encadeamento retroativo a partir da necessidade, dias **úteis**, gravado uma única vez via `insertOrIgnore`) vs. `recalcularTendencia()` (sempre sobrescrita, reflete replanejamento). `statusDoItem()` calcula/persiste `StatusItemSuprimento` (NoInicio/EmAndamento/EmRisco/Atrasado/Concluido). Alerta contratual 21/10 dias (`verificarMarcoDeAlerta()`, 1x por marco). Ponte automática com Restrição (`App\Support\SincronizarRestricaoSuprimento`): 1 Restrição por par (item, atividade), via `origem_suprimento_item_id`, nunca deleta — só resolve/reabre a mesma linha.

`[RECOMENDAÇÃO OPERACIONAL]` Use o **mecanismo legado** quando não há necessidade de rastreabilidade formal por documento (LM/LI). Use a **cadeia formal** quando a demanda nasce de um item real do Take Off, com congelamento de snapshots e histórico auditável ponta a ponta.

### 7.2 Cadeia formal completa (Ciclo 19)

```
ItemTakeOff → RequisicaoPlanejamento (RP) → RequisicaoPlanejamentoItem
   → AlocacaoRequisicaoPacote (Pacote×Material)
   → RequisicaoCompra (RC) → RequisicaoCompraItem + RequisicaoCompraEtapa
   → PedidoCompra → PedidoCompraItem
   → RecebimentoPedido (append-only, evento físico)
```

**Filosofia repetida em cada elo**: Rascunho nunca consome saldo oficial; emissão = transação única, revalidação total sob lock, congelamento de snapshot, numeração sequencial via lock em `Work`; `UNIQUE(obra_id, numero)` é defesa final, nunca mecanismo principal; imutabilidade pós-emissão via Observer (barreira semântica — atomicidade real vem sempre do `lockForUpdate()` no chamador).

| Elo | Status | Congelamento | Validação de saldo |
|---|---|---|---|
| **RequisicaoPlanejamento** | Rascunho\|Emitida | 7 campos `*_snapshot` na emissão | Saldo do `ItemTakeOff` (outras RPs Emitidas) |
| **AlocacaoRequisicaoPacote** | — | — | Saldo do RPItem (outras alocações) |
| **RequisicaoCompra** | Rascunho\|Emitida\|**Concluida (derivada)** | 7 campos snapshot + fluxo instanciado (etapas próprias, nunca reaproveita `ItemSuprimentoEtapa`) | Saldo oficial via RC Emitida/Concluída (`quantidadeConsumidaOficialPorRc()`) |
| **PedidoCompra** | Rascunho\|Emitido | Snapshots direto de RC Item + Fornecedor **fresco, nunca soft-deletado** | Saldo oficial via Pedido Emitido |
| **RecebimentoPedido** | append-only | — | Over-recebimento bloqueado (`SUM ≤ quantidade_pedida`) |

**Conclusão da RC é sempre DERIVADA** — quando a última etapa pendente recebe `data_realizada` (em qualquer ordem — ordem não é dependência de execução), a própria Action transiciona `Emitida→Concluida`. `fimPrevisto()` (última etapa) vs. `dataProjetadaAtendimento()` (MAIOR `data_prevista_entrega` entre Pedidos Emitido) coexistem sempre, nunca uma substitui a outra.

`[BUG SUSPEITO — corrigido]` Fornecedor soft-deletado entre rascunho e emissão do Pedido causava emissão silenciosa sem `fornecedor_nome_snapshot`. Corrigido com query fresca explícita, bloqueando com exceção.

`[BUG SUSPEITO — corrigido]` `dataConclusaoRecebimento()` originalmente ordenava por ordem de **registro**, produzindo data errada sob backdating fora de ordem — corrigido para ordenar primariamente por `recebido_em` (cronologia física), `created_at`/`id` só como desempate do mesmo dia.

**Concorrência — ordem de lock consolidada**: `RequisicaoPlanejamento`→`ItemTakeOff` · `Work`→`RequisicaoPlanejamento`→todos `ItemTakeOff` (`orderBy id`) · `RequisicaoPlanejamentoItem`→`ItemSuprimento`→`AlocacaoRequisicaoPacote` · `ItemSuprimento` (criação de RC) · `RequisicaoCompra`→`AlocacaoRequisicaoPacote` · `Work`→`RequisicaoCompra`→todas Alocações · `RequisicaoCompra`→`RequisicaoCompraEtapa` · `PedidoCompra`→`RequisicaoCompraItem` · `Work`→`PedidoCompra`→Fornecedor→todos RequisicaoCompraItem · `PedidoCompraItem` (recebimento).

### 7.3 Necessidade e folga de atendimento

`ItemSuprimento::necessidade()` — data mais cedo entre atividades vinculadas **ativas** (`fora_do_cronograma=false`, correção do Ciclo 19.3 — atividade arquivada nunca antecipa/atrasa artificialmente o cálculo). `dataProjetadaAtendimento()` — MÁXIMO entre `RequisicaoCompra::dataProjetadaAtendimento()` de todas as RCs formais do Pacote. `folgaAtendimento()` = `atendimento - necessidade`; `folga<0` = risco. Terminologia deliberada: "Risco de atendimento"/"Folga até necessidade", **nunca** "impacto no cronograma". Nunca cria Restrição automaticamente aqui.

### 7.4 Alertas automáticos da cadeia formal (Ciclo 19.7)

`App\Support\Suprimentos\AlertaCadeiaSuprimento` — 3 estados nunca tratados como um só: **Risco projetado** (nunca cria Restrição), **Atraso comercial do Pedido** (nunca cria Restrição), **Falta efetiva na necessidade** (única condição que abre/mantém Restrição bloqueante, granularidade Atividade+Pacote). Idempotência por UUIDv5 sobre chaves lógicas estáveis no tempo. Disparo em 2 vias: eventos diretos (emissão de Pedido, Recebimento) + Command diário `05:10` (deriva pura de calendário).

### 7.5 Industrialização em Terceiros (Ciclo 20.5)

**Princípio central**: remessa ao terceiro é modelada como Saída+Entrada correlacionadas — **nunca um tipo novo de movimentação** (só `Entrada`/`Saida` existem no ledger).

```
OrdemIndustrializacao (Fornecedor + Local Terceiro)
   → ProdutoIndustrializado (Material + Revisão de Documento congelada)
   → RemessaIndustrializacao (Envio/RetornoSobra) → ProdutoIndustrializadoConsumo
   → ProducaoIndustrializada
   → EntregaProdutoIndustrializado (RetornoEstoqueObra | EntregaDiretaCampo)
```

- **Remessa**: trava os 2 Locais em ordem determinística por `id` (evita deadlock); saldo sempre escopado ao Local de origem.
- **Consumo**: reduz saldo físico via `MovimentacaoEstoque::Saida` LIVRE no Local Terceiro (transformação física), nunca um tipo novo.
- **Produção**: `Entrada` técnica no Local Terceiro. `[LIMITAÇÃO]` sem limite de over-produção (só exibida como divergência).
- **Entrega**: as duas modalidades sempre geram Entrada técnica no Local próprio; `EntregaDiretaCampo` reaproveita de verdade `RegistrarSaidaEstoque` (3ª movimentação real).
- **Genealogia bidirecional** (`GenealogiaIndustrializacao`) — nunca infere distribuição automática, só lê o explicitamente registrado.

`[BUG SUSPEITO — corrigido, 20.5.CORREÇÃO]` `UnidadeEstoque.local_estoque_id` era atualizado a cada remessa, tornando o restante da bobina inacessível na obra de origem. Corrigido: o campo vira só "local de criação/origem", imutável — saldo por Local sempre derivado do ledger.

---

## 8. Estoque

*(Investigação fresh-read de `app/Models/{Material,LocalEstoque,UnidadeEstoque,MovimentacaoEstoque,DestinacaoPlanejadaMaterial,ReservaEstoque,AplicacaoMaterialEstoque,TransferenciaEstoque,InventarioEstoque*}.php`, `app/Actions/Estoque/*`, `app/Support/Estoque/*`.)*

### 8.1 Fundação — Material, Local, Unidade, Ledger

**`Material`** — catálogo mestre TENANT-WIDE (nunca por obra — o mesmo SKU serve várias obras). `Material` ≠ `ItemTakeOff`: a associação `ItemTakeOff.material_id` é opcional e manual, imutável condicional (ver 6.3/7.3). 3 modos de rastreabilidade: **Quantitativo** (fungível, sem `UnidadeEstoque`), **Lote** (bobina fracionável), **Serializado** (peça única, quantidade sempre 1).

**`LocalEstoque`** — posição física/custódia, **≠ FrenteTrabalho** (que é aplicação operacional). 5 tipos, incluindo `Terceiro` (custódia externa, sempre com `fornecedor_id`) — bloqueado nos fluxos comuns de Entrada/Reserva/Saída/Transferência (só via Ordem de Industrialização).

**`UnidadeEstoque`** — identidade física opcional (bobina/lote/serial). `[LIMITAÇÃO IMPORTANTE, corrigida]` `local_estoque_id` **não é** "onde está agora" — é só local de criação/origem, imutável. "Onde está e quanto tem em cada Local" é sempre derivado via `SaldoEstoque::porUnidadeLocal()`.

**`MovimentacaoEstoque`** — ledger append-only, única fonte de verdade de saldo. Só 2 tipos existem (`Entrada`/`Saida`) — Transferência/Ajuste/Remessa de Industrialização são **sempre pares correlacionados**, nunca um tipo novo. Sinal contábil centralizado (`TipoMovimentacaoEstoque::fatorSaldo()`) — toda agregação usa `CASE tipo ... THEN quantidade*fator` construído dinamicamente do enum, nunca `SUM` cru.

**`SaldoEstoque`** — biblioteca de agregação, sempre `static`, sempre em lote: `porUnidade()`/`porUnidadeLocal()`/`porMaterialLocal()`/`porMateriais()` (lote)/`porMateriaisNaObra()` (Ciclo 21.1)/`incorporadoDeRecebimento()`/`posicoesNoLocal()`.

**Entrada** (`RegistrarEntradaEstoque`): 1 `RecebimentoPedido` pode gerar N entradas (fracionamento normal). Material obrigatório — sem `ItemTakeOff.material_id` resolvido, entrada é bloqueada com mensagem didática. Bloqueios: Material/Local inativo, Local Terceiro, over-entrada. `[LIMITAÇÃO]` sem domínio de qualidade/inspeção — entrada é sempre disponível no instante do registro.

### 8.2 Destinação Planejada e Reserva (Ciclo 20.2)

**Duas camadas independentes, tetos diferentes:**

| | Destinação Planejada | Reserva de Estoque |
|---|---|---|
| Natureza | Lógica (intenção) | Física (comprometimento real) |
| Teto | Demanda formal do Pacote (soma de Alocações) | Saldo físico real |
| Cria Movimentação? | Nunca | Nunca |

`DestinacaoPlanejadaMaterial` — reaproveita 100% a cadeia RPItem→Alocação→Pacote (`ConciliacaoDestinacao::quantidadeFormal()`), over-destinação bloqueada. `ReservaEstoque` — Pacote obrigatório desde a criação (mesmo sem Frente); imutabilidade real via Observer (bloqueia `save()`/`update()` de instância — a única transição `Ativa→Liberada` é mass-update de Query Builder, imune ao Observer por natureza do framework); teto duplo (`min(disponível global, físico no Local)`).

`CoberturaReservas` — `deficit = max(0, reservado_ativo - físico)`, **nunca atribuído automaticamente** a uma Reserva/Frente específica (Decisão Opção F). "Reposição necessária" = o mesmo número, outro rótulo.

`[RECOMENDAÇÃO OPERACIONAL]`: Planejamento cria Destinação → Almoxarifado cria Reserva a partir dela (ou avulsa) → Retirada pode ou não referenciar a Reserva.

### 8.3 Saída física (Ciclo 20.3)

`RegistrarSaidaEstoque` — cardinalidade máx. 1 Reserva por Saída. **[RECOMENDAÇÃO OPERACIONAL crítica]** Saída sem Reserva valida contra o **saldo físico total**, nunca contra o não-reservado — consumir estoque reservado para outra demanda é **permitido, nunca bloqueado** (cenário de emergência); a Reserva original nunca é tocada automaticamente. Retirante: exatamente um (usuário interno com acesso à obra, ou texto externo) — nunca os dois, nunca nenhum.

`[RECOMENDAÇÃO OPERACIONAL]` Saída = consumo definitivo esperado; Transferência (8.5) = só muda de posição, continua no controle de estoque; Ajuste de Inventário (8.6) = correção formal por divergência de contagem.

### 8.4 Conciliação / Aplicação Real (Ciclo 20.4)

**4 fatos distintos, nunca confundidos**: (A) Destinação Planejada — o que se **pretendia**; (B) Reserva — o que foi **comprometido**; (C) Saída Física — o que **saiu**; (D) Aplicação Real — onde foi **efetivamente utilizado**.

`AplicacaoMaterialEstoque` — sempre referencia uma Saída; Pacote por LINHA (pode variar dentro da mesma Saída se ela não tiver Pacote fixo); Frente sempre real (`NOT NULL`); editável até fechar (`SUM(aplicações) ≥ quantidade`), congela em 100%.

`ConciliacaoAplicacao` (Planejado×Real) é **só aritmética**, nunca afirma causalidade. `DesviosAplicacao` só afirma causalidade quando há rastreabilidade direta (Saída consome Reserva com Destinação com Frente planejada) — sem isso, `porSaida()` retorna `null`, nunca um valor inventado.

### 8.5 Transferência entre Locais (Ciclo 20.6)

`TransferenciaEstoque` — modelada como Saída (origem) + Entrada (destino) correlacionadas na mesma transação, **nunca um tipo novo**. `UnidadeEstoque.local_estoque_id` nunca atualizado. Reservas: mesma filosofia da Saída (saldo físico total, nunca toca Reserva automaticamente). Concorrência: os 2 Locais travados em ordem determinística por `id` (evita deadlock entre transferências opostas concorrentes). Local Terceiro nunca envolvido (só via Industrialização).

### 8.6 Inventário Físico (Ciclo 20.7)

**5 estágios**: `Rascunho → EmContagem → EmAnalise → Concluido`, com `Cancelado` a partir de qualquer estágio aberto (nunca DELETE — Observer bloqueia incondicionalmente).

**Inventário NÃO sobrescreve saldo — nunca**: um Ajuste aprovado gera uma `MovimentacaoEstoque` **nova** (Entrada ou Saída conforme o sinal), nunca correção retroativa de linha existente. Snapshot (`InventarioItem.quantidade_sistema_snapshot`) congelado no instante em que passa a `EmContagem`, populado em lote via `SaldoEstoque::posicoesNoLocal()`.

**Contagem cega**: UI omite a coluna "Sistema" enquanto `EmContagem` — backend sempre calcula a diferença real, "cega" é só apresentação. Recontagem sempre append-only (`ContagemInventario`); "contagem adotada" = a mais recente por ordem de **registro**.

**Serial inesperado**: registro textual, **nunca cria `UnidadeEstoque` nova** — evita inventar identidade física sem origem comercial rastreável.

**Ajuste — dupla autorização**: exige `estoque.inventario|editar` **E** `estoque.movimentacao|editar` simultaneamente (checado no chamador, não na Action). Aprovação sempre revalida saldo **fresco sob lock** — nunca o snapshot antigo (ajuste negativo impossível é recusado).

### 8.7 Identificação por QR Code (Ciclo 20.8)

`ResolverCodigoEstoque`/`GeradorCodigoEstoque` — o código só identifica a entidade (`MAT:{ulid}`/`UNI:{ulid}`/`LOC:{ulid}`), **nunca carrega saldo/quantidade/Local atual**. Cross-tenant: ULID de outro tenant simplesmente não resolve (mesma mensagem de "desconhecido" — nunca vaza existência). Material/Local inativo nunca bloqueia a resolução ("resolver ≠ autorizar"). Zero dependência nova (`bacon/bacon-qr-code`, já vendorizada via Fortify).

### 8.8 UI unificada — abas e permissões

| Aba | Conteúdo | Slug |
|---|---|---|
| Materiais | CRUD `Material`, saldo consolidado | `estoque.movimentacao` |
| Locais | CRUD `LocalEstoque` | `estoque.movimentacao` |
| Recebimentos | Associar Material, dar entrada a partir de Recebimento | `estoque.movimentacao` |
| Saídas | Retirada física | `estoque.movimentacao` |
| Transferências | Entre Locais próprios | `estoque.movimentacao` |
| Movimentações | Histórico, só leitura | `estoque.movimentacao|ver` |
| Planejamento | Destinação/Reserva | `estoque.reserva` |
| Conciliação | Aplicação real, Planejado×Real, Desvios, Cobertura | `estoque.conciliacao` |
| Industrialização | Ordem, Remessa, Produção, Consumo, Entrega | `estoque.industrializacao` |
| Inventário | Sessões, contagem, ajuste | `estoque.inventario` (+ `estoque.movimentacao|editar` para aprovar) |

`[RECOMENDAÇÃO OPERACIONAL]` Encarregado opera o dia a dia (entrada/saída/transferência/conciliação/contagem); Engenheiro administra/aprova; GerentePlanejamento decide destinação por Frente (único slug em que nem o Encarregado tem `criar`) e é o único que exclui.

---

## 9. Importações, Exportações e Relatórios do Sistema

*(Investigação fresh-read de `app/Imports/*`, `app/Exports/*`, `resources/views/exports/*`, `app/Models/Report.php`, `App\Services\ReportGerador`, `App\Support\Report\DiagnosticoReport`.)*

### 9.1 Inventário de Importações

| Importador | Formato | Chave de reconciliação | Cria? | Atualiza? | Arquiva/exclui? | Onde |
|---|---|---|---|---|---|---|
| `MsProjectImporter` (Baseline) | `.xml` MSPDI | `external_uid`+`obra_id` | Sim | Sim | Sim (`fora_do_cronograma=true`) | `/app/radar/cronograma` |
| `MsProjectImporter` (Avanço) | `.xml` MSPDI | `external_uid` (só update) | **Não** | Sim (só progresso) | **Não** | `/app/radar/relatorios/importar-avanco` |
| `DocumentoEngenhariaImporter` | `.xlsx`/`.xlsm` (aba "LD") | `codigo`+`obra_id` | Sim (documento) | Sim + acrescenta revisão | Não | `/app/engenharia/pacotes` |
| `TakeOffImporter` | `.xlsx`/`.xlsm` (aba "TAKEOFF") | `codigo`+`lista_engenharia_id` | Sim | Sim | Não | `/app/engenharia/take-off` |

`[LIMITAÇÃO]` Não há importação de: Requisição do Planejamento, RC, Pedido, Fornecedor, catálogo de Estoque avulso, usuários em massa — tudo cadastro manual. `[LIMITAÇÃO]` Nome de aba exato exigido (`"LD"`/`"TAKEOFF"`) — erro de digitação gera `RuntimeException` sem fuzzy-match. `[LIMITAÇÃO]` Nenhum "desfazer importação" na UI.

### 9.2 Inventário de Exportações

**Excel** (`app/Exports/*`, Laravel Excel):

| Classe | Exporta | De qual tela | Estrutura |
|---|---|---|---|
| `RestricoesExport` | Restrições filtradas | Quadro de Restrições | 1 aba |
| `LookaheadExport` | Atividades da janela/filtros ativos | Lookahead | 1 aba |
| `LinhaBaseAtividadesExport` | Atividades de uma Baseline | Linhas de Base | 1 aba |
| `PlanoSemanalExport` | Atividades da semana ativa | Plano Semanal | 1 aba |
| `SuprimentosExport` | Pacotes com necessidade/previsto/tendência | Mapa de Suprimentos | 1 aba |
| `ObrasExport` | Grade de obras filtrada | Minhas Obras | 1 aba |
| `RelatorioRestricoesExport` | 10 indicadores | Relatórios de Restrições | 10 abas, reaproveita os MESMOS `#[Computed]` da tela |
| `CentralProntidaoExport` | 9 seções | Central de Prontidão | 9 abas, mesmo array do PDF irmão |
| `ReportExport`/`ReportCurvaExport`/`ReportDesviosExport` | Report semanal completo | Relatório Detalhe | múltiplas abas, sempre a partir de dados **congelados** |

**PDF** (DomPDF): `lookahead-pdf`/`-arvore-pdf`, `linha-base-atividades-pdf`, `plano-semanal-pdf`, `central-prontidao-pdf`, `relatorio-restricoes-pdf`, `suprimentos-pdf`, `obras-pdf`, `report-pdf`, `grd-pdf`, `grd-comprovante-entrega-pdf`, `grd-comprovante-recolhimento-pdf`, `etiqueta-estoque-pdf`. Todos disparados direto do componente Livewire (`Pdf::loadView()`+`streamDownload()`), nunca via Controller dedicado.

`[IMPLEMENTADO]` Todos os exports honram os filtros/dados já computados pela tela no momento do clique — nenhuma divergência encontrada entre tela e export. `[RECOMENDAÇÃO OPERACIONAL]` Validar exports sempre com filtros ativos, não só "tudo".

### 9.3 Relatórios do Sistema (visão consolidada)

| Relatório/Cockpit | Público-alvo | Pergunta central | Periodicidade sugerida |
|---|---|---|---|
| Report Semanal | Planejamento → Obra/Cliente | Como avançamos e por quê? | Semanal |
| Relatórios de Restrições | Gerente de Planejamento | Saúde do processo de restrições | Semanal/quinzenal |
| Central de Prontidão | Planejamento/Engenharia/Suprimentos | O que está pronto para execução? | Semanal |
| Benchmarking entre Obras | Diretoria do tenant | Qual obra performa melhor/pior? | Mensal/trimestral |
| Dashboard | Qualquer usuário da obra | Como a obra está agora? | Diária |
| Cockpit Executivo | Gerente de Obra/Projeto | O que pode parar a obra e onde agir? | Diária |
| Cockpit de Suprimentos | Gestor de Suprimentos | Onde a cadeia de compra ameaça o cronograma? | Diária/semanal |
| Cockpit de Engenharia | Gestor de Engenharia | O que falta liberar para construção? | Semanal |

### 9.4 Report Semanal — ciclo completo

**Ciclo**: `Rascunho → Emitido` (`Report::emitir()`, único caminho, nunca `update()` genérico). Filosofia de **fotografia**: `ReportGerador::gerarRascunho()` é o único ponto que lê dado ao vivo — depois, curvas/desvios/indicadores são gravados e nunca mais recalculados.

**Dupla trava** (`ReportPolicy`): Rascunho só quem tem `editar`; Emitido vira somente-leitura, visível/comentável por qualquer perfil com `ver`.

**Curvas S** — 3 séries (Previsto/Realizado/Tendência) rebaseadas ao mesmo denominador. **Quadro de Desvios** — peso/percentuais/impacto por linha (fórmulas em `ReportGerador::calcularLinhaDesvio()`). **Indicadores da Semana** — 6 linhas por Report (categoria×janela), sempre relativas a `periodo_referencia` (nunca `now()`).

**Os 8 diagnósticos** (`App\Support\Report\DiagnosticoReport::calcular()`): Confiabilidade do Cronograma, Principais Desvios, Causas do Desvio, HH Exposta por Atraso, Impacto de Restrições, Top Riscos, Próximos Eventos Relevantes, Aderência ao Planejamento — mais **Decisões Prioritárias** (9º, sempre calculado junto). Todos reaproveitados tanto no assistente de criação quanto no detalhe já emitido — nunca duplicados.

**Link público para cliente**: `URL::temporarySignedRoute` de 30 dias, sem login, escopo a 1 Report emitido — resolve tenant via `DB::table()` cru e `TenantContext::actingAs()`.

**Report automático semanal (opt-in por obra)**: `Work.dia_semana_report` + `GerarReportsAutomaticoCommand` (diário 06:00) — cria **só o rascunho** (emissão continua manual, dupla trava preservada).

---

## 10. Gestão: Cockpits Executivos, Situações Gerenciais e Notificações

*(Investigação fresh-read de `app/Support/Gestao/*`, `app/Support/Engenharia/*`, `app/Enums/{TipoSituacaoGerencial,SeveridadeSituacao,...}.php`, `app/Notifications/**`, `app/Console/Commands/{Sincronizar*,Notificar*}.php`.)*

### 10.1 Guia de leitura dos 3 Cockpits Executivos

Princípio comum: **read models de composição pura** — nenhum recalcula regra de negócio, só compõe `SituacoesGerenciaisQuery`/`CoberturaMaterialAtividadeQuery`/serviços de domínio já existentes.

#### Cockpit Executivo da Obra (`radar.cockpit`, `gestao.cockpit`)
- **Panorama**: contagem por severidade/tipo/domínio — 100% em memória sobre a coleção já obtida (0 queries). Não é score de saúde, só contagem bruta.
- **Riscos (top 10)**: severidade Crítica/Alta, ordenados por `chaveOrdenacao()` (impacto → proximidade temporal → severidade → atraso → desempate estável).
- **Ações Hoje**: não-Informativa e ainda não listada em Riscos.
- **Prontidão por Horizonte (2/4/8 semanas)**: `CoberturaMaterialAtividadeQuery` chamada **uma vez** com 56 dias, fatiada em memória. Fórmula do percentual: `cobertas / (total - informacaoInsuficiente)` — `InformacaoInsuficiente` nunca no numerador/denominador.
- **Matriz de Prontidão Futura**: JOIN em memória entre cobertura material e prontidão operacional (Central de Prontidão) por atividade.
- **Pipeline de Suprimentos**: linha crua por Material, **nunca soma valores entre materiais** (unidades incompatíveis).
- **Suprimentos × Cronograma**: `MaterialCritico` enriquecido com `folgaAtendimento()`.
- **Fornecedores**: ordenados por pedidos atrasados/abertos, **nunca** um "Top 5 piores" inventado.
- **Estoque/Industrialização/Inventário**: contagens de situações, nunca totais físicos somados entre materiais.

`[LIMITAÇÃO]` sem drill-down direto para a lista de Informativas; sem campos financeiros (domínio de compras não tem preço em nenhuma tabela); `Fornecedor` nunca se relaciona com `DocumentoEngenharia` ("documentos pendentes" é gap real, não decisão).

#### Cockpit de Suprimentos e Abastecimento (`radar.cockpit-suprimentos`, `gestao.suprimentos`)
Reaproveita 7 métodos `public static` de `CockpitObraQuery`. Blocos exclusivos: **Compras Pendentes** (cascata de saldos: a_requisitar/a_alocar/a_colocar_em_rc/a_colocar_em_pedido), **Pedidos Críticos** (priorizados por menor folga associada, não só maior atraso), **Recebimentos** (exceções: previsto hoje/7 dias/vencidos/parciais/sem destinação), **Chega Tarde Demais** (`FaixaFolgaAtendimento`: Positiva/Pequena/Atrasada/SemPrevisãoConfiável).

#### Cockpit de Engenharia e Liberação para Construção (`radar.cockpit-engenharia`, `gestao.engenharia`)
Compõe `InteligenciaEngenhariaQuery` (22.1) + `SituacoesGerenciaisQuery` (21.2). **Ação Prioritária** une `DocumentoBloqueante` com os 4 fatos de Engenharia (GRD aguardando aceite, cópias obsoletas, industrialização com mudança de revisão, suprimento bloqueado por documento) — campo `duplaRestricao` é puramente informativo. **Prontidão Documental por Horizonte**: `EstadoProntidaoEngenharia` (Liberada/Parcial/Bloqueada/InformacaoInsuficiente).

### 10.2 As 12 Situações Gerenciais (`TipoSituacaoGerencial`)

| # | Tipo | Gatilho | Severidade | Política de entrega |
|---|---|---|---|---|
| 1 | `MaterialCritico` | Par (Pacote,Material) em `SemCobertura`/`AguardandoCompra`/`DeficitAposConsumoEmergencial` | Calculada por proximidade+gravidade | Imediato a partir de Alta, cooldown 4h |
| 2 | `ReservaDescoberta` | Reserva Ativa com `reservado>físico` | Crítica sempre | Imediato |
| 3 | `PedidoAtrasado` | Pedido Emitido vencido | Crítica(>14d)/Alta | **Suprimida** (evita duplicar `AlertaCadeiaSuprimento` legado) |
| 4 | `RecebimentoPendente` | Pedido Emitido, entrega incompleta, dentro do prazo | Informativa | Sempre digest |
| 5 | `MaterialSemDestinacao` | Alocação com saldo a destinar >0 | Atenção | Sempre digest |
| 6 | `SaidaSemConciliacao` | Saída com aplicação pendente >0 | Atenção | Sempre digest |
| 7 | `DesvioAplicacao` | Aplicação divergente da Frente planejada | Atenção | **Nunca imediato**, mesmo crítica (evita soar acusatório) |
| 8 | `InventarioAguardandoDecisao` | Inventário `EmAnalise` | Alta fixa | Imediato |
| 9 | `DocumentoBloqueante` | Documento não liberado com atividade vinculada no horizonte | Alta/Atenção | Imediato a partir de Alta |
| 10 | `IndustrializacaoPendente` | Ordem com pendente >0 | Informativa | Sempre digest |
| 11 | `MaterialParado` | Sem movimentação há ≥60 dias | Informativa | Sempre digest |
| 12 | `GrdAguardandoAceite` | Destinatário sem aceite ativo | Informativa sempre (sem SLA) | Sempre digest |

`[RECOMENDAÇÃO OPERACIONAL — o que fazer quando aparece]` ver detalhamento por tipo no levantamento original; resumo: `MaterialCritico`/`ReservaDescoberta` → acelerar compra/investigar déficit; `DocumentoBloqueante` → cobrar liberação de Engenharia; `InventarioAguardandoDecisao` → aprovar/rejeitar Ajuste; `DesvioAplicacao`/`SaidaSemConciliacao`/`MaterialSemDestinacao` → completar registro de conciliação, nunca é bloqueante.

Mecanismo de deduplicação: `unique(chaveLogica)` + `sortBy(chaveOrdenacao())` — nunca duplica o mesmo fenômeno, nunca inventa prioridade fora da fórmula.

### 10.3 Notificações — canais e inventário

**Canais**: `database` (sino/Central), `broadcast` (Reverb, tempo real), `mail`, WhatsApp (`ZApiChannel`, best-effort, silencioso sem credencial), + wrappers de idempotência por ledger (`GrdLedgerMailChannel`/`ZApiChannel`, `SituacaoLedgerMailChannel`).

**Achado transversal — ordem dos canais importa sob fila síncrona**: `broadcast` sempre precisa ser o **último** do array `via()` (sob `QUEUE_CONNECTION=sync`, uma exceção em `broadcast` — que sempre falha fora do navegador — aborta os canais seguintes do `foreach`). Corrigido nas Notifications do Ciclo 21; `[DÍVIDA TÉCNICA]` ainda presente em algumas Notifications de GRD do Ciclo 18.5.6 (registrado, não corrigido).

**Idempotência universal**: UUIDv5 determinístico atribuído a `notification->id` (PRIMARY KEY) antes do envio — mesma técnica em GRD, Suprimentos legado e Situações Gerenciais.

**Digests agendados**: Prontidão Semanal (segunda 08:00), Pendências GED (segunda 08:00), Situações Gerenciais (diário 07:30) — todos com `Cache::lock()` + marcador de "já enviado", nunca duplicam.

### 10.4 Central de Notificações (UI)

Sino/dropdown: badge = **comunicações não lidas**, nunca "problemas ativos" (o Cockpit é quem mostra isso). Central completa: filtros por obra/tipo/severidade (só funcionam para Notifications que carregam esses campos no payload — legadas não). "Ativa/Resolvida" (estado da ocorrência) e "lida/não lida" são dimensões **independentes**, nunca inferidas uma da outra.

**Segurança do deep-link** (`notificacoes.abrir`): ownership sempre via `$request->user()->notifications()->find()` (nunca `DatabaseNotification::find()` cru); revalida acesso à obra **no momento do clique**, não no momento do envio.

---

## 11. Lições Aprendidas — Memória Organizacional

*(Investigação fresh-read de `app/Models/LicaoAprendida*.php`, `app/Enums/{StatusLicaoAprendida,TipoLicaoAprendida,...}.php`, `App\Support\LicoesAprendidas\*`, `app/Actions/LicoesAprendidas/*`.)*

### 11.1 Modelo e workflow de status

`LicaoAprendida` — obra de origem, disciplina opcional, `titulo`/`situacao_observada`/`recomendacao_futura` obrigatórios (causa/impacto/ação/resultado sempre opcionais, mesmo para o tipo "Problema" — decisão deliberada de não burocratizar o formulário).

**Workflow** (todas as transições via Action dedicada, nunca `update()` genérico):
```
Rascunho ──EnviarLicaoParaValidacao──▶ EmValidacao
Rascunho ◀──DevolverLicaoParaRascunho── EmValidacao
EmValidacao ──PublicarLicaoAprendida──▶ Publicada  (exige completude estrutural)
Publicada ──ArquivarLicaoAprendida──▶ Arquivada
```
**Publicada/Arquivada são imutáveis** (`estaImutavel()`) — corrigir conteúdo publicado é sempre arquivar + criar uma lição nova, nunca um `update()` retroativo (mesmo idioma já usado em GRD/RC/RP: "emitido nunca edita de volta").

### 11.2 Origem e proveniência

3 formas de nascer, mutuamente exclusivas: **Manual** (formulário em branco), **Captura contextual** (a partir de um link `?origem_tipo=X&origem_id=Y` no Lookahead/Estoque — revalida acesso independente do usuário à entidade de origem antes de pré-preencher), **Conversão de Candidato**. `LicaoAprendidaVinculo.e_origem` garante estruturalmente no máximo 1 vínculo de origem por lição (índice `UNIQUE` sobre coluna `STORED GENERATED`).

### 11.3 Geração de candidatos (3 regras automáticas)

`GerarCandidatosLicoesObra` — sempre manual (botão "Atualizar candidatos"), idempotente por `chave_logica` (defesa real é `UNIQUE` no banco):

| Regra | Condição |
|---|---|
| `RestricaoRelevante` | Restrição bloqueante Resolvida, duração ≥1 dia |
| `PedidoAtrasoFinal` | Pedido Emitido com `diasAtrasoFinal()` não nulo |
| `AplicacaoDesvioDestinacao` | Aplicação em Frente diferente da planejada, só em Saídas já fechadas |

Um candidato **Pendente** vira `Convertido` (gera a Lição) ou `Descartado` (nunca deletado — permanece navegável) — status **terminal**, nunca volta a Pendente.

`[LIMITAÇÃO/DECISÃO NECESSÁRIA]` Geração é 100% manual — **nenhum Command agendado** chama isso automaticamente (diferente de outros mecanismos análogos do projeto). Se ninguém clicar, candidatos elegíveis podem ficar meses sem aparecer.

### 11.4 Reutilização contextual

`LicoesContextuaisQuery` — só considera lições **Publicadas**, sempre exclui a obra de origem, critérios de correspondência **só 2, sem IA/texto**: `MesmoMaterial` (FK real via `ItemSuprimento`) e `MesmaDisciplina` — ambos tenant-wide. Aparece no popup de detalhe de Atividade no Lookahead e na linha de Material no Estoque, batch-safe.

### 11.5 Indicadores corporativos

`InteligenciaLicoesQuery::resumo()` — total publicadas, obras contribuintes, distribuição por área/disciplina/tipo/criticidade, evolução temporal, materiais cross-obra. **Linguagem sem causalidade indevida**: nunca afirma incidência real ou score de maturidade — só distribuição do que foi publicado.

### 11.6 Reaplicação consciente e avaliação

Granularidade sempre **Lição × Obra** (`UNIQUE(licao_aprendida_id, obra_id)`). `RegistrarReaplicacaoLicao`/`AvaliarReaplicacaoLicao` são as únicas Actions do projeto que revalidam a Policy internamente com ator **explícito** (nunca `Auth::user()` implícito) — seguras mesmo se o chamador esquecer de checar.

Avaliações são **append-only** — sem coluna de "resultado atual"; resultado corrente = a mais recente por ordem de **registro**. `ResultadoAvaliacaoReaplicacao` (Positivo/Parcial/Negativo/NaoAplicavel) significa estritamente "a equipe registrou essa avaliação" — **nunca** prova formal de que a lição evitou um problema.

### 11.7 Autorização e UI

Slug único `gestao.licoes-aprendidas` (ESCOPO_TENANT). **Regra central da Biblioteca Corporativa**: lição **Publicada** é visível a qualquer usuário do tenant com `ver` em qualquer obra; Rascunho/EmValidacao/Arquivada só visível a quem tem `ver` na obra de origem específica. `observacoes_internas` nunca vazado cross-obra.

UI: `gestao.licoes-aprendidas` — 3 abas (Biblioteca, Revisão da Obra, Inteligência Corporativa).

### 11.8 Fluxo end-to-end (10 estágios reconstruídos)

```
1. Fato operacional (Restrição resolvida / Pedido atrasado / Aplicação divergente)
2. Candidato (geração manual, idempotente)
3. Análise humana (Converter ou Descartar)
4. Lição — Rascunho
5. Validação (EmValidacao — Publicar ou Devolver)
6. Publicação (Publicada — imutável)
7. Memória corporativa (visível tenant-wide, agregada)
8. Reutilização contextual (aparece em outras obras)
9. Reaplicação consciente (registro explícito Lição×Obra)
10. Avaliação histórica (append-only)
```

### 11.9 [RECOMENDAÇÃO OPERACIONAL] Rotina proposta

- **Semanalmente, antes da reunião de obra**: clicar "Atualizar candidatos" (idempotente, sem risco).
- **Na reunião semanal**: item de pauta fixo (5 min) para decidir Converter/Descartar candidatos pendentes.
- **Mensalmente/em marcos**: criação manual de lições qualitativas que os candidatos automáticos não capturam.
- **Antes de Publicar**: alguém com papel de governança revisa pensando "isto ajudaria outra obra do tenant?" — publicação é irreversível em conteúdo.
- **Ao iniciar obra nova**: consultar a Biblioteca filtrando por Área/Disciplina relevante e "Materiais cross-obra".
- **Reaplicação**: registrar no mesmo instante da decisão consciente de adotar; agendar retorno em 30-60 dias para avaliar resultado.

`[DECISÃO NECESSÁRIA]` Se a geração manual de candidatos se mostrar esquecida na prática, avaliar introduzir um Command agendado (mesmo padrão de `SincronizarSituacoesGerenciaisCommand`) com alerta via infraestrutura de Notificações já existente.

---

## 12. Inventário Técnico Consolidado

*(Investigação fresh-read exaustiva de `database/migrations/*` — 192 arquivos —, `app/Observers/*`, `app/Console/{Kernel.php,Commands/*}`, `app/Jobs/*`.)*

### 12.1 Inventário de Entidades (matriz completa por módulo)

#### Cronograma / Restrições / Prontidão
| Tabela | Model | Escopo | SoftDelete | Observer |
|---|---|---|---|---|
| `pacotes_trabalho` | PacoteTrabalho | Obra | Não | — |
| `atividades` | Atividade | Obra | Sim | `AtividadeObserver` |
| `atividade_comentarios` | AtividadeComentario | Obra | Não | — |
| `atividade_anexos` | AtividadeAnexo | Obra | Não | — |
| `causas_nao_cumprimento` | CausaNaoCumprimento | Tenant | Não | — |
| `disciplinas` | Disciplina | Tenant | Não | — |
| `frentes_trabalho` | FrenteTrabalho | Obra | Sim | — |
| `etapas`, `entregaveis`, `equipes_responsaveis`, `personalizados_1..5` | (lookups) | Obra | Não | — |
| `categorias_restricao` | CategoriaRestricao | Tenant | Não | — |
| `restricoes` | Restricao | Obra | Não | `RestricaoObserver` |
| `restricao_acoes` | RestricaoAcao | Obra | Não | — |
| `itens_prontidao` / `atividade_itens_prontidao` | ItemProntidao / AtividadeItemProntidao | Obra | Não | — |
| `cronograma_importacoes` / `_health_checks` | CronogramaImportacao(HealthCheck) | Obra | Não | — |
| `avanco_periodos` / `curva_ajustes` | AvancoPeriodo / CurvaAjuste | Obra | Não | — |
| `linhas_base` | LinhaBase | Obra | Não | — |
| `atividade_snapshots` (+3 filhas de Fotografia O/P) | AtividadeSnapshot* | Obra | Não | — |
| `inconsistencias_avanco` | InconsistenciaAvanco | Obra | Não | — |
| `programacoes_semanais` / `_itens` | ProgramacaoSemanal(Item) | Obra | Não | — |
| `planos_acao` | PlanoAcao | Obra | Sim | — |
| `plano_acao_reconciliacoes` | PlanoAcaoReconciliacao | Obra | Não (append-only) | — |

#### Engenharia / GED / GRD
| Tabela | Model | Escopo | SoftDelete | Observer |
|---|---|---|---|---|
| `pacotes_engenharia` | PacoteEngenharia | Obra | Não | — |
| `documentos_engenharia` | DocumentoEngenharia | Obra | Não | — |
| `documento_engenharia_revisoes` | DocumentoEngenhariaRevisao | Obra | Não | `DocumentoEngenhariaRevisaoObserver` |
| `status_documentos_engenharia` | StatusDocumento | Tenant | Não | — |
| `documento_engenharia_reprogramacoes` | DocumentoEngenhariaReprogramacao | Obra | Não (append-only) | — |
| `revisao_liberacoes` | RevisaoLiberacao | Obra | Não (append-only) | `RevisaoLiberacaoObserver` |
| `documento_engenharia_atividades` | DocumentoEngenhariaAtividade | Obra | Não | — |
| `destinatarios` | Destinatario | Obra | Sim | — |
| `grds` | Grd | Obra | Sim | `GrdObserver` |
| `grd_itens` / `_destinatarios` / `_distribuicoes` | Grd* | Obra | Não | — |
| `grd_recolhimentos` | GrdRecolhimento | Obra | Não (append-only) | — |
| `grd_aceites_entrega` | GrdAceiteEntrega | Obra | Não | `GrdAceiteEntregaObserver` |
| `grd_alerta_entregas` | GrdAlertaEntrega | Tenant | Não | — |
| `unidades_medida` / `familias_material` | UnidadeMedida / FamiliaMaterial | Tenant | Não | — |
| `listas_engenharia` | ListaEngenharia | via Revisão | Não | `ListaEngenhariaObserver` |
| `itens_take_off` | ItemTakeOff | via Lista | Sim | `ItemTakeOffObserver` |

#### Suprimentos (legado + formal)
| Tabela | Model | Escopo | SoftDelete | Observer |
|---|---|---|---|---|
| `requisicoes_planejamento` / `_itens` | RequisicaoPlanejamento(Item) | Obra | Sim/Não | `RequisicaoPlanejamentoObserver` |
| `alocacoes_requisicao_pacote` | AlocacaoRequisicaoPacote | Obra | Não | — |
| `requisicoes_compra` / `_itens` / `_etapas` | RequisicaoCompra* | Obra | Sim/Não | `RequisicaoCompraObserver` |
| `pedidos_compra` / `_itens` | PedidoCompra(Item) | Obra | Sim/Não | `PedidoCompraObserver` |
| `recebimentos_pedido` | RecebimentoPedido | Obra | Não (append-only) | `RecebimentoPedidoObserver` |
| `fornecedores` | Fornecedor | Obra | Sim | — |
| `feriados` | Feriado | Tenant | Não | — |
| `fluxos_suprimento` / `etapas_fluxo_suprimento` | FluxoSuprimento(Etapa) | Tenant | Não | — |
| `itens_suprimento` | ItemSuprimento | Obra | Sim | `ItemSuprimentoObserver` |
| `item_suprimento_atividades` / `_documentos` / `_comentarios` | pivôs | Obra | Não | — |
| `itens_suprimento_etapas` / `_etapa_datas` | ItemSuprimentoEtapa(Data) | Obra | Não | — |

#### Estoque / Inventário
| Tabela | Model | Escopo | SoftDelete | Observer |
|---|---|---|---|---|
| `materiais` | Material | Tenant | Sim | `MaterialObserver` |
| `locais_estoque` | LocalEstoque | Obra | Sim | `LocalEstoqueObserver` |
| `unidades_estoque` | UnidadeEstoque | via Local | Não | — |
| `movimentacoes_estoque` | MovimentacaoEstoque | Obra | Não (append-only) | `MovimentacaoEstoqueObserver` |
| `destinacoes_planejadas_material` | DestinacaoPlanejadaMaterial | Obra | Não | `DestinacaoPlanejadaMaterialObserver` |
| `reservas_estoque` | ReservaEstoque | Obra | Não | `ReservaEstoqueObserver` |
| `aplicacoes_material_estoque` | AplicacaoMaterialEstoque | Obra | Não | `AplicacaoMaterialEstoqueObserver` |
| `transferencias_estoque` | TransferenciaEstoque | Obra | Não (append-only) | `TransferenciaEstoqueObserver` |
| `inventarios_estoque` | InventarioEstoque | Obra | Não | `InventarioEstoqueObserver` |
| `inventario_itens` | InventarioItem | Obra | Não | `InventarioItemObserver` |
| `contagens_inventario` | ContagemInventario | Obra | Não (append-only) | `ContagemInventarioObserver` |
| `inventario_ajustes` | InventarioAjuste | Obra | Não | `InventarioAjusteObserver` |

#### Industrialização em Terceiros
| Tabela | Model | Escopo | SoftDelete | Observer |
|---|---|---|---|---|
| `ordens_industrializacao` | OrdemIndustrializacao | Obra | Sim | `OrdemIndustrializacaoObserver` |
| `produtos_industrializados` | ProdutoIndustrializado | Obra | Sim | `ProdutoIndustrializadoObserver` |
| `remessas_industrializacao` | RemessaIndustrializacao | Obra | Não (append-only) | `RemessaIndustrializacaoObserver` |
| `produto_industrializado_consumos` | ProdutoIndustrializadoConsumo | Obra | Não | `ProdutoIndustrializadoConsumoObserver` |
| `producoes_industrializadas` | ProducaoIndustrializada | Obra | Não | `ProducaoIndustrializadaObserver` |
| `entregas_produto_industrializado` | EntregaProdutoIndustrializado | Obra | Não | `EntregaProdutoIndustrializadoObserver` |

#### Lições Aprendidas
| Tabela | Model | Escopo | SoftDelete | Observer |
|---|---|---|---|---|
| `licoes_aprendidas` | LicaoAprendida | Obra (origem) | Sim | `LicaoAprendidaObserver` |
| `licao_aprendida_vinculos` | LicaoAprendidaVinculo | Obra | Não | — |
| `licao_aprendida_evidencias` | LicaoAprendidaEvidencia | Obra | Não | — |
| `licao_aprendida_candidatos` | CandidatoLicaoAprendida | Obra | Não | — |
| `licao_aprendida_reaplicacoes` (+contextos/avaliações) | LicaoAprendidaReaplicacao* | Obra | Não | Observers próprios (append-only) |

#### Gestão / Report / Cadastros / Admin / Cobrança
| Tabela | Model | Escopo | SoftDelete | Observer |
|---|---|---|---|---|
| `situacao_ocorrencias` / `situacao_comunicacao_entregas` | SituacaoOcorrencia(Entrega) | Obra/Tenant | Não | — |
| `reports` (+7 tabelas filhas) | Report* | Obra | Não | — |
| `tenants` | Tenant | — | Sim | — |
| `users` | User | **sem BelongsToTenant** | Sim | — |
| `tenant_user`, `clients`, `works`, `user_work` | pivôs/cadastro | Tenant | Não | — |
| `perfis` / `perfil_permissoes` | Perfil(Permissao) | Tenant | Não | — |
| `convites` | Convite | Tenant | Não | — |
| `planos` / `assinaturas` / `assinatura_faturas` | Plano/Assinatura* | Global/**sem tenant** | Não | — |
| `impersonacoes` | Impersonacao | — | Não | — |
| `avisos_plataforma` (+pivôs) | AvisoPlataforma | Global | Não | — |
| `feedbacks` | Feedback | Tenant | Não | — |

**Total mapeado: ~100 tabelas de domínio** (excluindo infraestrutura como `sessions`/`jobs`/`cache`).

### 12.2 Estados e Transições — inventário completo

| Entidade | Estados | Transição chave | Reversível? |
|---|---|---|---|
| **Atividade** (`StatusAtividade`) | Planejado→Comprometido→EmExecucao→Concluido\|NaoConcluido | `AtividadeObserver::updating()` grava/limpa `concluido_em`; bloqueia `Comprometido` se `!estaPronta()` | Sim (via ação humana) |
| **Restricao** (`StatusRestricao`) | Aberta→EmTratamento/AguardandoTerceiros→Resolvida | `resolver()`/`reabrirRestricao()` | Sim — reabertura manual ou automática (Plano de Ação) |
| **Report** (`StatusReport`) | Rascunho→Emitido | `Report::emitir()` | **Não** |
| **Grd** (`StatusGrd`) | Rascunho→Emitida | `EmitirGrd::execute()` | **Não** |
| **RequisicaoPlanejamento** | Rascunho→Emitida | `EmitirRequisicaoPlanejamento` | **Não** |
| **RequisicaoCompra** | Rascunho→Emitida→**Concluida (derivada)** | Última etapa com `data_realizada` transiciona automaticamente | **Não** |
| **PedidoCompra** | Rascunho→Emitido | `EmitirPedidoCompra` | **Não** |
| **OrdemIndustrializacao** | Rascunho→Emitida→Concluida (planejada, sem gatilho implementado) | `EmitirOrdemIndustrializacao` | **Não** |
| **InventarioEstoque** | Rascunho→EmContagem→EmAnalise→Concluido\|Cancelado (a qualquer estágio aberto) | Actions dedicadas por transição | **Não** (todos terminais) |
| **LicaoAprendida** | Rascunho→EmValidacao→Publicada→Arquivada | Actions dedicadas; Publicada/Arquivada imutáveis | **Não** a partir de Publicada |
| **CandidatoLicaoAprendida** | Pendente→Convertido\|Descartado | `ConverterCandidatoEmLicao`/`DescartarCandidatoLicaoAprendida` | **Não** (terminal) |
| **ProgramacaoSemanal** | Aberta→Fechada (+ versionamento paralelo) | `FecharProgramacaoSemanal`/`CriarRevisaoProgramacaoSemanal` | Não muda versão antiga; nova revisão é sempre possível |
| **InconsistenciaAvanco** | Aberta→Tratada | `TratarInconsistenciaAvanco` | **Não** (sem reabertura nesta fase) |
| **SituacaoOcorrencia** | Ativa↔Resolvida (+episódio) | Automática, via `SincronizarSituacoesGerenciais` | **Sim** — única entidade com reabertura automática real |
| **PlanoAcao** | Aberta↔Resolvida/Cancelada | `PlanoAcaoReconciliador` | Sim, mas Resolvida/Cancelada nunca trocam direto entre si |
| **ReservaEstoque** | Ativa→Liberada | `LiberarReservaEstoque` | **Não** |

### 12.3 Automações — inventário completo

**Commands agendados** (`app/Console/Kernel.php`):

| Command | Frequência | Faz | Nunca faz |
|---|---|---|---|
| `suprimentos:recalcular-status` | Diário 05:00 | Recalcula status legado, alertas 21/10 dias | Não cria Restrição diretamente |
| `suprimentos:sincronizar-cadeia-formal` | Diário 05:10 | Deriva situação da cadeia formal, `AlertaCadeiaSuprimento` | Não sincroniza com o legado |
| `reports:gerar-automatico` | Diário 06:00 | Cria RASCUNHO de Report (obras com `dia_semana_report`) | Nunca emite automaticamente |
| `assinaturas:processar` | Diário 07:00 | Faturas, inadimplência | Nunca cobra sem gateway configurado |
| `prontidao:notificar-semanal` | Segunda 08:00 | Digest de prontidão | — |
| `engenharia:notificar-pendencias-grd` | Segunda 08:00 | Digest GED | Nunca mail/WhatsApp (só database+broadcast) |
| `backup:run`/`backup:clean` | Diário 03:00/04:00 | Backup só do banco | Nunca inclui arquivos/`.env` |
| `gestao:sincronizar-situacoes` | A cada 15 min | Despacha `SincronizarSituacaoObraJob` por obra | Nunca processa direto no Command |
| `gestao:digest-situacoes` | Diário 07:30 | Digest agregado por domínio | Nunca recalcula, só lê `SituacaoOcorrencia` |

**Jobs**: `ImportarCronogramaJob` (Health Check+Score+Fotografia F/O/P+Detector, 1 transação); `SincronizarSituacaoObraJob` (`ShouldBeUnique`, lock estrutural por obra).

**Observers de imutabilidade** (33 registrados): todos seguem "Observer = barreira semântica; transação do chamador = atomicidade real" — listados na coluna Observer da matriz 12.1.

**Listeners**: `EnviarBoasVindasAposVerificarEmail` (evento `Verified`, nunca reenvia).

### 12.4 Dados Derivados vs. Persistidos — inventário completo

Princípio-mestre citado em dezenas de docblocks: *"o ledger/histórico é o fato; o estado é sempre uma pergunta feita a ele, nunca uma resposta guardada."*

| Dado derivado | Classe/método | Por que nunca é persistido |
|---|---|---|
| Saldo físico de estoque | `SaldoEstoque::*` | Ledger append-only; um saldo persistido divergiria a cada novo tipo de movimentação |
| Saldo reservado/disponível | `SaldoReserva::*` | `ReservaEstoque.quantidade` nunca decrementada por consumo |
| Prontidão de Atividade | `Atividade::scopeProntas()` | "Derivada das restrições em aberto — nunca uma coluna nem uma tabela" (regra central do produto) |
| Cobertura Material×Atividade | `CoberturaMaterialAtividadeQuery` | Cruza 3+ fontes em tempo real; persistir ficaria obsoleto a cada Entrada/Reserva/Saída |
| Pipeline de Suprimentos | `PipelineMaterialQuery` | Agrega a cadeia de FKs inteira; evita double-counting quando 2+ ItemTakeOff apontam pro mesmo Material |
| Folga de atendimento | `ItemSuprimento::folgaAtendimento()` | `necessidade()` muda a cada reprogramação — persistir congelaria um valor que deve ficar sempre "vivo" |
| Situações Gerenciais (12 tipos) | `SituacoesGerenciaisQuery::porObra()` | Só o CICLO DE COMUNICAÇÃO é persistido (`SituacaoOcorrencia`), nunca o fato em si |
| Status de recebimento/entrega de Pedido | `PedidoCompraItem::statusRecebimento()` / `PedidoCompra::situacaoEntrega()` | Soma de `RecebimentoPedido` (append-only) |
| Score de Saúde do cronograma | `ScoreCalculator::calcular()` | **Exceção deliberada**: persistido por importação como snapshot imutável |
| Aderência da Programação Semanal | `ProgramacaoSemanal::aderencia()` | Lido ao vivo da Atividade, nunca campo próprio |
| Estado de uma GrdDistribuicao | `GrdDistribuicao::estado()` | Calculado a partir do último `GrdRecolhimento` por ordem de registro |
| Cópias obsoletas/candidatos GRD | `DetectorCopiasObsoletasGrd`/`CandidatosNovaEntregaGrd` | Alerta derivado, recalculado a cada leitura |
| Déficit/recomposição de reserva | `CoberturaReservas::porPares()` | Sempre recomputado; nunca atribuído automaticamente a uma Reserva específica |
| Conciliação de Aplicação pendente | `PoliticaConciliacaoAplicacao`/`ConciliacaoAplicacao` | `pendente = saída - SUM(aplicações)` |
| Desvio de Aplicação | `DesviosAplicacao::porSaidasEmLote()` | Só existe quando há causalidade real rastreável |
| Saldo de matéria-prima/produto industrializado | `SaldoMateriaPrimaIndustrializacao`/`SaldoProdutoIndustrializado` | Nunca rotulado automaticamente como perda/sucata |
| Material parado | `MaterialParadoQuery` | `MAX(ocorrido_em)` sempre em tempo de leitura |
| Prontidão Documental de Engenharia | `ProntidaoDocumentalAtividadeQuery` | Consistente por construção com `scopeProntas()` |
| Resumo Executivo dos Cockpits | `ResumoExecutivoGerencial` | 100% em memória sobre coleção já obtida — 0 queries |
| Resultado corrente de reaplicação de Lição | `LicaoAprendidaReaplicacao::resultadoAtual()` | Avaliações append-only; resultado = a mais recente por ordem de registro |

---

## 13. Rotinas Operacionais Propostas

*(Síntese autoral — proposta de processo, nunca imposta pelo código.)*

### 13.1 Rotina diária (todos os perfis operacionais)

1. **Abrir o Dashboard/Cockpit Executivo** (quem tem acesso) — visão geral em &lt;2 minutos: quantas situações críticas/altas, quantas atividades bloqueadas no horizonte.
2. **Almoxarifado/Encarregado**: dar entrada em recebimentos pendentes de incorporação; registrar saídas do dia; tratar Inconsistências de Avanço geradas na última importação.
3. **Engenheiro**: revisar documentos aguardando liberação com atividade próxima vinculada (via Cockpit de Engenharia ou Central de Prontidão).
4. **Todos**: checar a Central de Notificações (badge do sino) e tratar o que exige ação imediata.

### 13.2 Rotina semanal

| Dia sugerido | Atividade | Quem | Onde |
|---|---|---|---|
| Segunda (após digests 08:00) | Revisar digest de Prontidão + digest GED + digest de Situações Gerenciais | Gerente de Planejamento | Central de Notificações |
| Antes da reunião de Lookahead | Importar avanço do cronograma da semana anterior | Gerente de Planejamento | `radar.relatorios.importar-avanco` |
| Reunião de Lookahead/Prontidão (script na seção 13.3) | Revisar Central de Prontidão, comprometer Plano Semanal | Toda a equipe de planejamento | `radar.central-prontidao` + `radar.plano-semanal` |
| Depois de fechar o Plano Semanal | Gerar/emitir Report semanal | Gerente de Planejamento | `radar.relatorios.novo` |
| Pauta fixa (5 min) | Revisar candidatos de Lições Aprendidas pendentes | Gerente de Planejamento | `gestao.licoes-aprendidas` (aba Revisão da Obra) |
| Coordenação Suprimentos/Engenharia | Consultar Cockpit de Suprimentos e Cockpit de Engenharia | Gestores respectivos | `radar.cockpit-suprimentos`/`-engenharia` |

### 13.3 Script sugerido para a reunião de Central de Prontidão / Lookahead

1. Abrir a Central de Prontidão com horizonte de 30 dias.
2. Ordenar por status — começar por **"Não pronta"**: para cada atividade, ler o motivo já exibido (restrição bloqueante / documento não liberado / checklist pendente) e decidir uma ação com prazo e responsável.
3. Passar por **"Atenção"**: mencionar riscos visíveis (restrição não-bloqueante, Suprimento em risco, Plano de Ação aberto) sem bloquear o compromisso — são "vigiar", não "resolver antes".
4. Agrupar por Pacote/EAP para identificar concentração de bloqueio numa única frente de trabalho.
5. No Plano Semanal, comprometer só as atividades marcadas "Pronta" (o sistema já impede comprometer as "Não pronta").
6. Fechar a Programação Semanal ("Gerar Programação") ao final da reunião — isso congela o compromisso e habilita revisão rastreável se algo mudar durante a semana.

### 13.4 Matriz de "quando registrar uma Restrição" (guia rápido)

| Situação | Registrar Restrição? | Bloqueante? |
|---|---|---|
| Falta material que a atividade depende | Sim | Sim, se sem material a atividade não pode começar |
| Documento de engenharia não liberado | O bloqueio já é automático via GED — registrar Restrição manual só se houver causa adicional | Depende |
| Falta de mão de obra/equipamento | Sim | Depende da criticidade real |
| Pendência informativa, sem impedir execução | Sim, mas `bloqueante=false` | Não |
| Achado do Health Check do cronograma | Considerar Plano de Ação primeiro; só vire Restrição se impedir uma atividade específica | Depende |

---

## 14. Matrizes de Gestão da Informação

### 14.1 Matriz "o que manter atualizado" — consolidada (cadastros + operação)

*(Estende a matriz de cadastros mestres do Capítulo 4.4 com dados operacionais dos demais módulos.)*

| Informação | Responsável típico | Frequência | Onde atualizar | O que fica errado se desatualizada |
|---|---|---|---|---|
| Cronograma (Baseline) | Gerente de Planejamento | A cada mudança relevante de escopo/prazo | Importar Cronograma | Toda a cadeia (Restrições, Suprimentos, Report) trabalha com dado desatualizado |
| Avanço físico (Realizado) | Gerente de Planejamento | Semanal | Importar Avanço | Curvas S, PPC, prontidão histórica ficam sem atualização; Farol do Lookahead fica neutro |
| Status/liberação de Documento de Engenharia | Engenheiro | A cada emissão/revisão | Lista de Documentos | Atividade fica bloqueada mesmo já pronta na prática (ou o contrário: liberada indevidamente) |
| GRD (distribuição física) | Engenharia/Documentação | A cada distribuição real | GRDs | Cópias obsoletas em campo não são detectadas |
| Take Off / associação Material | Engenharia + Suprimentos | Ao importar/revisar LM/LI | Take Off / Estoque | Sem associação, Recebimento fica bloqueado para incorporar ao estoque |
| Requisição do Planejamento / Alocação | Planejamento | Conforme demanda | Requisições do Planejamento | Suprimentos não enxerga a necessidade formal |
| Pedido de Compra (data prevista) | Suprimentos | Na emissão | Mapa de Suprimentos | Sem data, `dataProjetadaAtendimento()` cai no fallback de fim de processo, menos preciso |
| Recebimento físico | Almoxarifado | A cada chegada | Estoque → Recebimentos | Saldo de estoque nunca reflete a realidade |
| Reserva/Destinação | Planejamento/Almoxarifado | Ao planejar consumo | Estoque → Planejamento/Reservas | Cockpit de Suprimentos mostra déficit sem causa aparente |
| Conciliação/Aplicação | Encarregado/Almoxarifado | Após aplicação em campo | Estoque → Conciliação | `SaidaSemConciliacao` acumula, rastreabilidade se perde |
| Inventário físico | Almoxarife | Periódico (ex.: mensal) | Estoque → Inventário | Divergência entre saldo do sistema e físico real nunca é corrigida |
| Candidatos de Lições Aprendidas | Gerente de Planejamento | Semanal | Lições Aprendidas → Revisão da Obra | Conhecimento organizacional se perde |
| Perfis de Acesso | Criador do tenant | Quando muda a equipe | Perfis de Acesso | Acesso inadequado (excessivo ou insuficiente) |

### 14.2 Matriz "fonte de verdade" (quem manda em cada dado)

| Dado | Fonte de verdade única | Nunca confundir com |
|---|---|---|
| Prontidão de uma Atividade | `Atividade::scopeProntas()` | Um campo digitado, ou a Central de Prontidão em si (que só lê essa mesma regra) |
| Data de conclusão de uma Atividade | `Atividade.concluido_em` | `real_termino` (do importador) ou `updated_at` |
| Estado de uma Distribuição de GRD | `GrdDistribuicao::estado()` (último evento por ordem de registro) | `ocorrido_em` (data informada, retroativável) |
| Saldo físico de estoque | Agregação de `MovimentacaoEstoque` via `SaldoEstoque` | Qualquer campo de "quantidade" em Material/Local |
| Onde uma bobina/lote está fisicamente | `SaldoEstoque::porUnidadeLocal()` | `UnidadeEstoque.local_estoque_id` (é só origem, imutável) |
| Revisão que governa um Documento | `DocumentoEngenharia::revisaoVigente()` | A liberação mais recente concedida (pode ser de uma revisão que já não é a vigente) |
| Se um Documento pode ser usado para construir | `estaLiberadoParaConstrucao()` | O status "conclusivo" do Documento (são conceitos diferentes) |
| Necessidade de material de um Pacote | `ItemSuprimento::necessidade()` (só atividades ativas) | Qualquer atividade arquivada vinculada |
| Situação gerencial ativa | `SituacoesGerenciaisQuery::porObra()` (sempre recalculado) | `SituacaoOcorrencia.status` sozinho, sem cruzar com a derivação atual |

---

## 15. Erros Operacionais Perigosos e Funcionalidades com Limitações Reais

### 15.1 Erros operacionais perigosos (o que um usuário pode fazer de errado e o sistema não impede)

| Erro | Consequência | Onde |
|---|---|---|
| Importar cronograma sem os campos `Texto20-30` preenchidos no template do MS Project | Disciplina/Frente/Entregável/Equipe/Personalizados nunca ficam disponíveis para cadastro manual — permanentemente vazios até a próxima importação com o campo populado | Importação de Cronograma |
| Ignorar avisos Críticos do Health Check e confirmar "Importar mesmo assim" | Datas invertidas, ciclos lógicos e outras inconsistências entram no cronograma oficial | Importação de Cronograma |
| Registrar Saída de estoque sem vincular Reserva quando existia uma correspondente | Consome estoque reservado para outra demanda sem visibilidade automática — só aparece depois em `CoberturaReservas`/`ReservaDescoberta` | Estoque → Saída |
| Esquecer de fazer a Conciliação/Aplicação de uma Saída | `SaidaSemConciliacao` se acumula, perde-se a rastreabilidade de "onde o material foi aplicado" | Estoque → Conciliação |
| Trocar o Material associado a um `ItemTakeOff` **antes** de qualquer Pedido/Destinação (janela em que ainda é permitido) sem avisar Suprimentos | Muda silenciosamente o que a cadeia de compra estava rastreando | Take Off ↔ Estoque |
| Deixar uma revisão de Documento sem liberação enquanto uma GRD rascunho já foi montada com ela | A emissão falhará só na hora de emitir (correto por design, mas exige reabrir o rascunho) | GRD |
| Confiar no Score de Saúde do cronograma como número absoluto de qualidade | O Score é ponderado por peso×proporção de atividades afetadas — um problema estrutural grave em poucas atividades (ex.: ciclo lógico) pode gerar Score quase 100 num cronograma grande | Health Check |
| Aprovar um Ajuste de Inventário sem checar se algo mudou o saldo entre a contagem e a aprovação | O sistema já revalida e bloqueia ajustes fisicamente impossíveis — mas um ajuste "possível porém errado" pode passar se ninguém investigar a causa da divergência | Inventário |
| Usar `estoque.curvas`/permissões "fantasma" (ver 15.2) achando que restringem edição | Falso senso de segurança de acesso | Curvas S / Causas / Matriz / Importar Avanço |

### 15.2 Funcionalidades que parecem completas mas têm limitações reais

Consolidação dos achados `[BUG SUSPEITO]`/`[LIMITAÇÃO]` mais relevantes de todos os capítulos:

1. **4 slugs de permissão sem enforcement real** (Capítulo 3.4): `obras.curvas` (editar ajuste de Curva S), `restricoes.causas`, `restricoes.matriz`, `report.importar_avanco` — os toggles existem na tela de Perfis de Acesso, mas não têm efeito prático nas 2 primeiras (telas só-leitura) nem bloqueiam a ação real nas outras 2.
2. **Reuso de slug impede granularidade de acesso** — `engenharia.pacotes` cobre Lista de Documentos+GRD+Take Off; `restricoes.lookahead` cobre Lookahead+Inconsistências de Avanço.
3. **Rota morta `/dashboard`** (Capítulo 3.3) — quebra com erro 500 se acessada diretamente.
4. **`/app/home` (primeira tela após login)** ainda tem texto de template não customizado.
5. **FrenteTrabalho sem cadastro manual** — depende inteiramente da importação de cronograma popular `Texto22`.
6. **Score de Saúde não tem "piso mínimo" por regra** — um ciclo lógico crítico pode ficar quase invisível no Score geral de um cronograma grande (decisão de calibração documentada, não corrigida).
7. **Geração de candidatos de Lições Aprendidas é 100% manual**, sem lembrete automático.
8. **Sem bloco financeiro em Suprimentos** — nenhuma tabela do domínio de compras (RC/Pedido/Fornecedor) tem campo de preço/valor/moeda.
9. **`Fornecedor` nunca se relaciona com `DocumentoEngenharia`** — "documentos pendentes por fornecedor" é uma pergunta que o sistema hoje não consegue responder.
10. **Sem estado "Cancelada"/"Cancelado"** em RP, RC, Pedido, Ordem de Industrialização, GRD — um processo formal já emitido não tem caminho de cancelamento explícito.
11. **`RequisicaoCompra`/`OrdemIndustrializacao` sem transição automática de conclusão** implementada para o segundo caso (planejada, sem gatilho real).
12. **Sem domínio de qualidade/inspeção/quarentena em Estoque** — toda entrada é considerada disponível instantaneamente.
13. **Excesso de estoque não é classificado automaticamente** (Material Parado é detectado, mas "excesso" exigiria cruzar com necessidade futura, não implementado).
14. **`InconsistenciaAvanco` sem reabertura** — tratamento é terminal.
15. **`/admin` sem RBAC interno** — qualquer admin da plataforma acessa tudo.

---

## 16. Roteiro de Teste Manual Completo

*(Proposta de roteiro, partindo de uma obra vazia, cobrindo o ciclo funcional completo do sistema.)*

### Etapa 0 — Conta
1. Registrar novo tenant (`/register`).
2. Confirmar e-mail.
3. Confirmar que os 5 perfis padrão foram semeados (tela Perfis de Acesso).

### Etapa 1 — Cadastros mestres
4. Cadastrar 1 Cliente.
5. Cadastrar 1 Obra vinculada ao Cliente.
6. Cadastrar Tipos de Restrição (opcional, mas recomendado antes de operar).
7. Cadastrar Itens de Prontidão (checklist adicional, opcional).

### Etapa 2 — Cronograma
8. Importar um cronograma MS Project (.xml) como Baseline, com os campos Texto20-30 preenchidos.
9. Revisar o Health Check da importação — confirmar que aparecem achados nas categorias esperadas (usar um XML propositalmente com alguma inconsistência para validar a exibição).
10. Confirmar a importação e verificar que Atividades/Pacotes foram criados.
11. Salvar uma Linha de Base.
12. Abrir o Lookahead e confirmar que o farol/Curva S da atividade aparece corretamente (sem Report ainda, deve mostrar indicador neutro).

### Etapa 3 — Restrições
13. Registrar 1 Restrição bloqueante numa atividade próxima.
14. Confirmar que a atividade aparece "Não pronta" na Central de Prontidão.
15. Resolver a Restrição.
16. Confirmar que a atividade volta a "Pronta".
17. Comprometer a atividade no Plano Semanal.
18. Fechar a Programação Semanal ("Gerar Programação").
19. Marcar a atividade como Concluída (ou Não Concluída + Causa) e verificar `concluido_em`.

### Etapa 4 — Engenharia
20. Cadastrar Pacote de Engenharia.
21. Importar uma Lista de Documentos (LD).
22. Vincular um Documento a uma Atividade.
23. Confirmar que a Atividade fica "Não pronta" por documento não liberado (`scopeProntas()`).
24. Liberar a revisão vigente do Documento.
25. Confirmar que a Atividade volta a ficar elegível.
26. Emitir uma GRD com esse Documento para 1 Destinatário.
27. Registrar o Aceite de Entrega e testar o QR Code de verificação pública.

### Etapa 5 — Take Off e Suprimentos
28. Importar Take Off (LM) sobre a revisão liberada.
29. Associar um item de Take Off a um Material do catálogo (Estoque).
30. Criar uma Requisição do Planejamento, alocar quantidade a um Pacote de Compra.
31. Criar uma Requisição de Compra a partir da alocação, emitir.
32. Criar um Pedido de Compra a partir da RC, emitir com Fornecedor e data prevista.
33. Confirmar que `folgaAtendimento()` do Pacote reflete a data do Pedido.

### Etapa 6 — Estoque
34. Registrar Recebimento do Pedido (Ciclo 19) e dar Entrada em Estoque a partir dele.
35. Criar uma Destinação Planejada + Reserva para uma Frente de Trabalho.
36. Registrar uma Saída vinculada à Reserva.
37. Registrar a Conciliação/Aplicação dessa Saída.
38. Abrir um Inventário do Local, contar, mover para Análise, aprovar um Ajuste (com as 2 permissões), Concluir.

### Etapa 7 — Report e Gestão
39. Gerar um Report semanal (assistente), revisar os 8 diagnósticos.
40. Emitir o Report; gerar o link público e acessá-lo sem login.
41. Consultar Dashboard, Cockpit Executivo, Cockpit de Suprimentos, Cockpit de Engenharia.
42. Consultar Central de Notificações e confirmar que pelo menos 1 Situação Gerencial apareceu (ex.: forçar `MaterialCritico` deixando um Pacote sem cobertura).

### Etapa 8 — Lições Aprendidas
43. Gerar candidatos na aba Revisão da Obra.
44. Converter 1 candidato em Lição, enviar para validação, publicar.
45. Confirmar que a Lição aparece na Biblioteca Corporativa de outra obra do mesmo tenant.
46. Registrar uma Reaplicação e uma Avaliação.

### Etapa 9 — Multi-perfil
47. Repetir pontos-chave logado como cada um dos 5 perfis padrão, confirmando os limites da tabela-resumo do Capítulo 3.5.

---

## 17. Especificação de Massa de Dados de Teste Realista

*(Proposta de massa mínima para demonstrar o sistema de ponta a ponta.)*

| Item | Quantidade sugerida | Observação |
|---|---|---|
| Clientes | 2 | Um "cliente real" e um genérico |
| Obras | 2-3 | Ao menos uma com `dia_semana_report` configurado |
| Usuários | 5-8 | Ao menos 1 por perfil padrão |
| Atividades (cronograma) | 150-300 | Volume suficiente para EAP com 3+ níveis, várias Disciplinas/Frentes |
| Predecessoras/sucessoras | Populadas em ≥80% das atividades | Necessário para regras estruturais/lógicas do Health Check |
| Restrições | 20-40, misturando bloqueante/não-bloqueante, várias categorias | Cobrir os 7 indicadores do relatório |
| Documentos de Engenharia | 30-50, com múltiplas revisões em pelo menos 10 | Para exercitar liberação/reprogramação |
| GRDs | 5-10, com recolhimentos parciais e ao menos 1 aceite | Exercitar detector de cópias obsoletas |
| Itens de Take Off | 50-100, associados a ~30 Materiais | Cobrir Curva ABC com massa suficiente para 3 classes |
| Cadeia de Suprimentos | 5-10 Pedidos em estágios diferentes (Rascunho/Emitido, com/sem atraso) | Exercitar Cockpit de Suprimentos |
| Movimentações de Estoque | 100+ (Entradas, Saídas, Transferências) | Volume mínimo para relatórios de conciliação fazerem sentido |
| Inventário | ≥1 sessão concluída com divergência e Ajuste aprovado | Demonstrar o fluxo completo |
| Lições Aprendidas | 5-10 publicadas, cobrindo os 4 tipos e pelo menos 2 obras diferentes | Demonstrar reutilização contextual cross-obra |
| Reports | 3-4 semanas consecutivas, ao menos 1 emitido com link público testável | — |

`[RECOMENDAÇÃO OPERACIONAL]` Preferir dados com datas relativas a `now()` no momento da carga (não datas absolutas fixas) — o próprio código do projeto já documenta problemas recorrentes de "calendar drift" em fixtures de teste com data absoluta.

---

## 18. Pré-requisitos para Demonstração Comercial

1. **Um tenant "demo" isolado**, nunca misturado com dados reais de clientes.
2. **Massa de dados conforme Capítulo 17**, previamente carregada e validada.
3. **Ao menos 1 obra em estágio "maduro"** (várias semanas de Report emitido, cronograma com avanço real) para mostrar Curvas S e histórico — e **1 obra "recém-criada"** para mostrar o fluxo de onboarding.
4. **Usuário de demonstração por perfil** (Admin, GerentePlanejamento, Engenheiro, Encarregado, ClienteLeitura) — já logado ou com troca fácil, para mostrar a diferença de visão por perfil.
5. **Ao menos 1 Situação Gerencial de cada severidade** ativa no momento da demo (Crítica/Alta/Atenção/Informativa) para mostrar os Cockpits com conteúdo real.
6. **Cenário de bloqueio de prontidão real** pronto para "destravar ao vivo" (ex.: liberar um Documento e mostrar a atividade saindo de "Não pronta").
7. **QR Code de GRD impresso** (ou em tela) para demonstrar a verificação pública ao vivo.
8. **Link público de Report** já gerado, para abrir numa aba anônima.
9. **Confirmar que `/app/home` não está mostrando o texto de template genérico** (Achado 15.2.4) antes de qualquer demo ao vivo.
10. **Revisar que nenhuma permissão "fantasma" (Capítulo 15.2.1) seja usada como argumento de segurança** durante a demo.

---

## 19. Funcionalidade × Benefício

| Funcionalidade | Benefício para o cliente |
|---|---|
| Prontidão sempre derivada (nunca um campo manual) | Elimina o "achismo" de que uma atividade está pronta — é sempre auditável e consistente |
| Health Check de importação (36 regras) | Detecta problemas de qualidade do cronograma antes que virem problema de execução |
| Fotografia F/O/P + Detector de Inconsistências | Rastreabilidade histórica: "o que o cronograma dizia" nunca se perde nem é reescrito por engano |
| Documento não liberado bloqueia prontidão automaticamente | Elimina o risco de construir sobre revisão errada por falha de comunicação |
| GRD com aceite/QR/comprovante | Prova formal e verificável de distribuição de documentos, reduzindo disputa/retrabalho |
| Cadeia formal de Suprimentos com folga de atendimento | Visibilidade antecipada de risco de atraso de material antes que ele pare a obra |
| Ledger de estoque (nunca saldo solto) | Saldo sempre confiável, auditável, sem divergência silenciosa |
| Conciliação/Aplicação real | Sabe exatamente onde cada material foi de fato aplicado, não só onde saiu do almoxarifado |
| Inventário com Ajuste formal e dupla autorização | Corrige divergência com controle e rastreabilidade, sem sobrescrever histórico |
| Cockpits Executivos | Decisão gerencial em minutos, sem precisar entrar em 5 telas diferentes |
| Situações Gerenciais + Notificações | Ninguém precisa "lembrar de checar" — o sistema avisa quando algo exige ação |
| Lições Aprendidas | Conhecimento operacional de uma obra vira ativo reutilizável em outras obras da mesma empresa |
| Report semanal com link público ao cliente | Comunicação profissional e transparente com o contratante, sem retrabalho manual de relatório |
| Perfis de acesso customizáveis | Cada empresa modela sua própria hierarquia de responsabilidade, sem depender de código |

---

## 20. Afirmações Comerciais Seguras vs. Perigosas

### Seguras (respaldadas por comportamento real do código)

- "A prontidão de cada atividade é sempre calculada automaticamente, nunca depende de um campo preenchido manualmente."
- "O sistema nunca deixa comprometer uma atividade com restrição bloqueante em aberto."
- "Toda GRD emitida é rastreável, com histórico de recolhimento e aceite verificável por QR Code."
- "O saldo de estoque é sempre um retrato fiel das movimentações reais registradas — nunca um número editável à parte."
- "Um Report emitido nunca muda seus números, mesmo que o cronograma seja reimportado depois."
- "O sistema separa cadastros de tenant e de obra, com isolamento de dados testado."

### Perigosas — evitar sem qualificação

- **Nunca dizer** "o sistema tem controle de custos/financeiro" — não há campo de preço em nenhuma tabela do domínio de compras.
- **Nunca dizer** "o sistema calcula o caminho crítico automaticamente" — `caminho_critico` é uma flag manual no MVP, sem cálculo de CPM.
- **Nunca dizer** "o Score de Saúde do cronograma é uma nota objetiva de qualidade" sem qualificar — pode subestimar problemas estruturais graves em cronogramas grandes (Capítulo 15.2.6).
- **Nunca dizer** "o sistema detecta automaticamente perdas/sucata na Industrialização" — a diferença não classificada é só exibida, nunca rotulada.
- **Nunca dizer** "todo perfil pode ser restrito com granularidade total" — há reuso de slug que impede separar GRD de Take Off/Lista de Documentos, por exemplo.
- **Nunca prometer** integração com ERP/ferramenta financeira externa sem confirmar que não existe API REST própria hoje.
- **Nunca dizer** "cancelamento formal de pedidos/requisições" — esse estado não existe na cadeia de Suprimentos.

---

## 21. Personas

### Gerente de Planejamento — "Ana"
Responsável pelo cronograma, restrições, PPC e reuniões de Lookahead. Usa o sistema diariamente. Precisa de visibilidade rápida sobre o que ameaça a próxima semana e histórico confiável de avanço.

### Engenheiro de Campo — "Carlos"
Mantém o Lookahead atualizado, trata restrições tecnicamente, acompanha liberação de documentos. Não tem poder de exclusão — trabalha dentro de limites definidos pela Gerência.

### Encarregado de Obra — "Marcos"
Executa no campo — registra causas de não-cumprimento, movimenta estoque, cria restrições e itens de Plano de Ação. Não vê Cockpits nem exclui nada.

### Gestor de Suprimentos — "Fernanda"
Acompanha o Cockpit de Suprimentos, a cadeia formal de compra e os alertas de atraso, decide priorização de fornecedores.

### Diretor de Operações — "Roberto"
Usa Benchmarking entre Obras e os 3 Cockpits Executivos para decisão multi-obra, quase nunca entra em telas operacionais de detalhe.

### Cliente Contratante — "Vopak" (persona corporativa)
Acesso somente-leitura, consulta Reports emitidos via link público ou perfil ClienteLeitura, acompanha avanço sem interferir na operação.

---

## 22. Glossário

| Termo | Significado |
|---|---|
| **Tenant** | A empresa (construtora) que contratou o sistema — fronteira de isolamento de dados |
| **Obra (Work)** | Unidade de contexto de trabalho dentro de um tenant |
| **Restrição** | Algo que impede a execução de uma atividade — precisa ser removido antes do compromisso semanal |
| **Prontidão** | Estado derivado: zero restrições bloqueantes + checklist completo + documentação liberada |
| **Lookahead** | Visão das próximas semanas do cronograma, com prontidão já calculada |
| **PPC** | Percentual do Plano Concluído — indicador do Last Planner System |
| **Health Check** | Motor de 36 regras que audita a coerência de uma importação de cronograma |
| **Score de Saúde** | Nota 0-100 calculada a partir dos achados do Health Check |
| **Fotografia F/O/P** | Snapshots imutáveis do que o cronograma (F), a plataforma (O) e a Programação Semanal (P) sabiam no instante de uma importação |
| **GED** | Gestão Eletrônica de Documentos — módulo de Documento/Revisão/Liberação |
| **GRD** | Guia de Remessa de Documentos — distribuição física rastreável de documentos |
| **Take Off** | Lista de Materiais (LM) ou Instrumentos (LI) extraída de um Documento de Engenharia |
| **Pacote de Compra** | `ItemSuprimento` — coordenador de workflow de compra de um ou mais materiais |
| **Requisição do Planejamento (RP)** | Formalização, pelo Planejamento, de que uma quantidade do Take Off deve seguir para compra |
| **Requisição de Compra (RC)** | Processo formal de compra sobre uma alocação, com etapas e prazos próprios |
| **Folga de Atendimento** | Diferença entre quando o material será atendido e quando é necessário |
| **Ledger de Estoque** | Registro append-only de todas as movimentações — única fonte de verdade de saldo |
| **Destinação Planejada** | Intenção lógica de quanto de um material vai para qual Frente de Trabalho |
| **Reserva de Estoque** | Comprometimento físico real de saldo contra uma demanda |
| **Conciliação/Aplicação** | Registro de onde um material efetivamente foi utilizado, depois de sair do estoque |
| **Situação Gerencial** | Fato derivado (1 de 12 tipos) que pode virar comunicação/alerta |
| **Cockpit** | Painel executivo de leitura consolidada (Executivo, Suprimentos, Engenharia) |
| **Lição Aprendida** | Registro de conhecimento organizacional, com workflow de publicação e reutilização cross-obra |
| **Report Semanal** | Fotografia congelada do avanço da obra, com 8 diagnósticos e link público |

---

## 23. Lacunas que Não Podem Ser Determinadas Só pelo Código

1. **Regra de negócio para classificar sucata/perda na Industrialização** — não existe no domínio; exige decisão de produto explícita.
2. **Se/quando cancelamento formal deve existir** em RP/RC/Pedido/Ordem de Industrialização/GRD.
3. **Se a geração de candidatos de Lições Aprendidas deve ser automatizada** (Command agendado) — hoje é decisão de uso, não de código.
4. **Granularidade de acesso a GRD separada de Lista de Documentos/Take Off** — depende de demanda real de clientes.
5. **Se o produto deve ter módulo financeiro/custo** — hoje inexistente por design, não por lacuna acidental.
6. **Threshold de calibração do Score de Saúde para regras estruturais graves** (ex.: piso mínimo por regra) — decisão de calibração ainda não tomada.
7. **Política de retenção de dados de Situações Gerenciais/Notificações** — nenhuma exclusão automática implementada; decisão de arquivamento pendente.
8. **Suporte a barcode 1D** — só QR Code implementado; decisão de investir em 1D depende de necessidade real do cliente.
9. **RBAC interno em `/admin`** — hoje todo admin da plataforma vê tudo; decisão de segmentar depende do tamanho da equipe de suporte.

---

## 24. Confirmação de Rastreabilidade das Regras Críticas

As regras de negócio mais críticas do sistema foram citadas, ao longo deste documento, com arquivo/classe/método rastreável — não apenas descritas em prosa. Confirmação por área:

- **Prontidão**: `app/Models/Atividade.php::scopeProntas()` (Capítulos 5.9, 6.1).
- **Imutabilidade de Report/GRD/RP/RC/Pedido/Lição Aprendida**: Observers dedicados citados nos Capítulos 6, 7, 8, 11, 12.1.
- **Saldo de Estoque**: `app/Support/Estoque/SaldoEstoque.php` (Capítulo 8.1, 12.4).
- **Health Check**: `app/Support/HealthCheck/HealthCheckEngine.php` + 36 classes de regra (Capítulo 5.1).
- **Cadeia de Suprimentos**: Actions de cada elo citadas no Capítulo 7.2, com ordem de lock documentada.
- **Situações Gerenciais**: `app/Support/Gestao/SituacoesGerenciaisQuery.php` (Capítulo 10.2).

Nenhuma afirmação de comportamento crítico neste documento foi feita sem citação de arquivo/classe/método correspondente nos capítulos de origem (3 a 12).

---

## 25. Checklist: "O Manual Consegue Responder Estas Perguntas?"

| Pergunta que um manual de implantação/usuário precisa responder | Este levantamento cobre? |
|---|---|
| Qual a ordem correta de cadastro numa obra nova? | Sim — Capítulo 4.2 |
| O que cada perfil pode/não pode fazer? | Sim — Capítulo 3.5 |
| Como interpretar a Central de Prontidão numa reunião? | Sim — Capítulo 5.10, 13.3 |
| Quando registrar uma Restrição e o que fazer depois? | Sim — Capítulo 5.9, 13.4 |
| Como funciona a liberação de documentos e seu impacto na prontidão? | Sim — Capítulo 6.1 |
| Como interpretar cada indicador dos 3 Cockpits? | Sim — Capítulo 10.1 |
| O que fazer quando uma Situação Gerencial aparece? | Sim — Capítulo 10.2 |
| Como funciona o ciclo de vida de uma Requisição/Pedido de Compra? | Sim — Capítulo 7.2 |
| Como o saldo de estoque é calculado e por que nunca diverge? | Sim — Capítulo 8.1, 12.4 |
| Como registrar e reaproveitar uma Lição Aprendida? | Sim — Capítulo 11 |
| Quais erros o usuário pode cometer sem o sistema impedir? | Sim — Capítulo 15.1 |
| Quais afirmações comerciais são seguras de fazer? | Sim — Capítulo 20 |
| Que dados de teste preciso para uma demonstração completa? | Sim — Capítulo 17 |
| **Qual é o preço/plano ideal para cada porte de cliente?** | **Não** — fora do escopo de código (Capítulo 23) |
| **Como o produto se compara a concorrentes específicos?** | **Não** — não é uma pergunta respondível por leitura de código |

---

## 26. Prontidão para os 7 Entregáveis Futuros

Avaliação de quão pronta está a base factual deste documento para alimentar cada entregável futuro mencionado no pedido original:

| Entregável futuro | Prontidão | Observação |
|---|---|---|
| 1. Manual de implantação/configuração | **Alta** | Capítulos 3, 4 e as ordens de dependência (4.2, 4.3) já dão a espinha dorsal |
| 2. Manual do usuário didático | **Alta** | Capítulos 5-11 têm linguagem operacional e exemplos, prontos para reformatação didática |
| 3. Roteiro operacional em ordem de uso | **Alta** | Capítulo 13 já propõe rotinas diária/semanal e script de reunião |
| 4. Manual por perfil/área | **Alta** | Capítulo 3.5 (perfis) + recortes por módulo já permitem montar manuais segmentados |
| 5. Checklists de cadastro/operação | **Média-Alta** | Capítulo 16 (roteiro de teste) e 4.2 (ordem de implantação) cobrem a maior parte; checklists formatados como formulário ainda precisam ser extraídos |
| 6. Guia de interpretação de telas/indicadores/Cockpits | **Alta** | Capítulo 10.1 é literalmente esse guia para os 3 Cockpits; falta somente extrair o mesmo nível de detalhe para telas operacionais individuais, se desejado |
| 7. Roteiro de treinamento | **Média-Alta** | Capítulos 3.5, 13 e 16 já compõem uma trilha; falta só a formatação em módulos de treinamento com duração/exercícios |
| 8. Material comercial / copy de vendas | **Média** | Capítulos 1, 19, 20, 21 dão a base factual segura; o texto de vendas em si (tom, formatação, design) ainda precisa ser escrito |
| 9. Demonstração comercial guiada | **Média-Alta** | Capítulo 18 já lista pré-requisitos; falta o roteiro passo-a-passo de clique-a-clique da demo em si |

**Conclusão**: este levantamento cobre a base factual completa exigida para iniciar qualquer um dos 7-9 entregáveis futuros. Nenhum deles foi produzido nesta tarefa — conforme instruído, esta é **apenas** a fase de inventário funcional.

---

## Fim do levantamento — STOP

Conforme instrução explícita do pedido original (Seção 57): **este documento encerra a tarefa de levantamento funcional integral**. Nenhuma correção de código foi feita, nenhum arquivo de código-fonte foi alterado, nenhuma tarefa de melhoria foi iniciada, e nenhum comando git foi executado. Os achados `[BUG SUSPEITO]`/`[DÍVIDA TÉCNICA]`/`[DECISÃO NECESSÁRIA]` catalogados ao longo deste documento estão registrados para revisão e decisão futura do usuário — não foram e não devem ser corrigidos como parte desta tarefa.


