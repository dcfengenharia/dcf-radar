# DCF Radar — Regras do projeto

SaaS multitenant (Laravel 10) para quadro de restrições baseado no
Last Planner System. Antecipa e remove impedimentos antes da execução.

## Arquitetura inegociável

**IMPORTANTE — isolamento de tenant é a joia da coroa de segurança:**
- Banco de dados ÚNICO, isolamento por linha via coluna `tenant_id`.
- Todo model de domínio USA o trait `App\Models\Concerns\BelongsToTenant`.
- O `tenant_id` é carimbado automaticamente no `create` pelo trait —
  nunca o defina à mão em código de request.
- **YOU MUST NOT** usar `withoutGlobalScope(TenantScope::class)` em
  código de request. Só em comandos/jobs de plataforma revisados,
  sempre dentro de `TenantContext::actingAs()`.
- Chaves primárias são ULID (`HasUlids`), nunca auto-incremento.
- Dois contextos distintos: **tenant** = fronteira de segurança
  (automática, via middleware); **obra** = contexto de trabalho que o
  usuário seleciona. Nunca os confunda.

## Hierarquia de dados

Tenant → Cliente → Obra → PacoteTrabalho (EAP auto-aninhada via
`parent_id`) → Atividade → Restricao → RestricaoAcao.
A Disciplina classifica a Atividade. A Causa de não cumprimento
pendura na Atividade.

O **Report semanal** é uma hierarquia paralela, pendurada na Obra:
Report → ReportCurva (uma curva por pacote escolhido pelo planejamento,
ou raiz = obra inteira quando `pacote_trabalho_id` é null) →
ReportCurvaDatapoint + ReportDesvio + ReportPontoAtencao. Report também
tem ReportFoto (galeria única do report, não por curva) e
ReportComentario (só em reports emitidos). Reaproveita
`SerieAvanco`/`GranularidadePeriodo` já existentes — não duplica enum.

## Regras de negócio centrais

**IMPORTANTE:**
- A **prontidão é DERIVADA** das restrições em aberto — nunca uma coluna
  nem uma tabela. Atividade pronta = ZERO restrições bloqueantes não
  resolvidas.
- Uma Atividade só vai ao plano semanal (status `comprometido` ou além)
  se estiver pronta.
- Marcar uma atividade como `nao_concluido` EXIGE registrar uma Causa.
- **`Atividade.concluido_em`** é a ÚNICA fonte da verdade pra "quando essa
  atividade foi realmente concluída" (usado pelo PPC histórico do
  Relatório de Restrições). Setado/limpo automaticamente pelo
  `App\Observers\AtividadeObserver::updating()` a cada transição de
  `status` — nunca setar/ler à mão em código novo. Não confundir com:
  `real_termino` (só o importador de MS Project grava, a partir do
  cronograma externo — nada a ver com o fluxo de "marcar concluída" do
  app) nem `updated_at` (mexido por reimportação, edição de linha de
  base, reordenação manual — não confiável pra saber quando a conclusão
  aconteceu de verdade).
- **Report é rascunho/emitido, dupla trava** (`App\Policies\ReportPolicy`,
  a única do projeto que combina PERMISSÃO e STATUS ao mesmo tempo):
  enquanto rascunho, só quem tem permissão `editar` em `report.relatorios`
  na obra vê/edita; ao emitir (ação dedicada `Report::emitir()`, nunca
  `update()` genérico), vira somente-leitura e todo perfil com permissão
  `ver` em `report.relatorios` passa a ver e comentar.
- **Report é fotografia, não vista ao vivo**: os números de cada curva
  (HH por período, %, término de linha de base/tendência, peso/desvio/
  impacto do quadro de desvios) são calculados e GRAVADOS no momento em
  que `App\Services\ReportGerador::gerarRascunho()` roda — nada num
  Report já criado volta a consultar `AvancoPeriodo`/`PacoteTrabalho` ao
  vivo depois. Mesma filosofia de `AtividadeSnapshot`/`LinhaBase`: um
  report já emitido e mostrado ao cliente nunca muda sozinho após uma
  reimportação do cronograma.
- **Alerta contratual de suprimento (21/10 dias)**: `App\Services\
  SuprimentoScheduler::verificarMarcoDeAlerta()`, chamado a cada item
  dentro do loop diário de `App\Console\Commands\
  RecalcularStatusSuprimentos::handle()` (já agendado, 05:00). Compara
  `ItemSuprimento::necessidade()` contra hoje em dias corridos; ao
  cruzar 21 ou 10 dias restantes, dispara
  `AlertaPrazoSuprimentoNotification` (mail+database+broadcast) pro
  `responsavel_id` do item (fallback `created_by_id`) — cada marco só
  uma vez por item, via as colunas `alerta_21d_enviado_em`/
  `alerta_10d_enviado_em` (nunca setadas à mão). Item `Concluido` ou sem
  atividade vinculada (sem `necessidade()`) nunca dispara.
- **Relatório semanal automático (opt-in por obra)**: `Work.dia_semana_report`
  (0=domingo..6=sábado, `null` = desligado, comportamento padrão). Job
  diário `App\Console\Commands\GerarReportsAutomaticoCommand` (agendado
  06:00, depois de `suprimentos:recalcular-status`) cria só o RASCUNHO —
  emissão continua manual, dupla trava preservada. Reaproveita
  `pacote_trabalho_id`/`ordem` das curvas do último report da obra (sem
  copiar `pontos_atencao`); `linha_base_id`/`avanco_importacao_id` ficam
  `null` de propósito, deixando `ReportGerador::cronogramaParaSerie()`
  resolver sempre a linha de base/avanço mais recente disponível (nunca
  repete a mesma importação de um report antigo). Obra sem nenhum report
  anterior, ou sem importação de avanço elegível (`RuntimeException` de
  `gerarRascunho()`), é pulada sem quebrar as demais obras do tenant.
- **Link público do cliente**: botão "Link para o cliente" em
  `⚡relatorio-detalhe.blade.php` (só report emitido) gera um signed route
  (`URL::temporarySignedRoute`, 30 dias) pra
  `App\Http\Controllers\ClienteRelatorioPublicoController` — acesso
  SEM LOGIN, escopo deliberadamente limitado a UM Report emitido
  específico (nunca o quadro de restrições ao vivo). Como o visitante é
  anônimo, `HasObraPapel`/o global scope de `BelongsToTenant` não têm
  como resolver o tenant sozinhos: o controller resolve o `tenant_id` do
  report com uma consulta `DB::table()` mínima e entra nele via
  `TenantContext::actingAs()` (nunca `withoutGlobalScope()` em código de
  request). A view (`resources/views/cliente/relatorio-publico.blade.php`)
  reaproveita `App\Support\ReportCurvaSerializer` — extraída de dentro do
  próprio `⚡relatorio-detalhe.blade.php` (que agora só delega pra ela)
  pra não duplicar a lógica de tabela/gráfico em dois lugares. **Achado
  desta fase:** `app/Exceptions/Handler.php::render()` intercepta
  qualquer 403 numa navegação de página cheia (autenticada) e redireciona
  pro popup interno de acesso negado — teria empurrado um visitante
  público com link expirado/inválido pro login; rotas `cliente.*` agora
  ficam de fora desse redirecionamento.

## Onboarding

- **Checklist único de manutenção**: `App\Support\Onboarding\OnboardingChecklist`
  (`passosTenant()`/`passosObra()`). Passo obrigatório mais recente:
  `restricao_cadastrada` — sem isso, o checklist parava em "importar
  cronograma" e nunca chegava no motivo do produto existir (o quadro de
  restrições do Last Planner System).
- **`App\Http\Middleware\RequireObraContext` bloqueia páginas do Radar**
  enquanto há pendência obrigatória — mas a rota que É a própria ação
  (`rotaAcao`) de um passo pendente sempre fica acessível, senão o
  usuário fica trancado fora da tela que precisa visitar pra concluir o
  passo (ex.: sem esse cuidado, faltar a 1ª restrição bloquearia
  justamente o Quadro de Restrições). `radar.cronograma` continua com
  exceção própria e incondicional (é o ponto de partida quando não há
  nem atividade). Ao adicionar um novo passo obrigatório em `passosObra()`,
  conferir se esse mesmo mecanismo de exceção cobre a rota certa.

## Planos e Assinatura

- **`Tenant::limiteObras()`/`limiteUsuarios()`/`limiteUploadMb()`**: únicos
  pontos de leitura do limite de plano — `null` em `limiteObras()`/
  `limiteUsuarios()` = ilimitado (tenant sem `Assinatura`, ou `Plano` com
  o campo vazio); `limiteUploadMb()` é diferente, tem teto técnico de
  fallback (`Tenant::LIMITE_UPLOAD_SEM_PLANO_MB`). Aplicados em
  `⚡obras/create.blade.php::createWork()` e em dois pontos do fluxo de
  convite (`⚡obra-detalhe.blade.php::enviarConvite()`, que dá feedback
  cedo, e `ConviteController::aceitar()`, que é a checagem que vale de
  verdade — o limite pode mudar entre o envio e a aceitação).
- **`StatusAssinatura::concedeAcesso()`** (`Trial`/`Ativa` = true,
  `Cancelada`/`Suspensa`/`Inadimplente` = false) é enforced por
  `App\Http\Middleware\EnsureTenantAssinaturaAtiva` (alias
  `assinatura.ativa`, no grupo de rotas `app`) — admin da plataforma
  (`is_platform_admin`) sempre passa; tenant sem nenhuma `Assinatura`
  também sempre passa (mesmo fallback "sem plano = sem restrição" usado
  em todo o resto do sistema).

## LGPD

- **Exportação de dados pessoais** (`App\Actions\ExportUserData::gerar()`,
  botão "Exportar meus dados" no Perfil via
  `App\Livewire\Profile\ExportUserDataForm`): monta um JSON com os dados
  do usuário + todo registro onde ele é autor/responsável, consultando
  direto via `DB::table()` (não Eloquent) as ~20 tabelas com FK pra
  `users` já mapeadas — sempre filtrando por `tenant_id` também, como
  defesa extra. Ao adicionar uma tabela nova com FK de autoria pra
  `users`, incluir aqui também. Exclusão de conta já existe via
  Jetstream (`profile.delete-user-form`) — mas várias dessas mesmas
  tabelas têm a FK como `restrictOnDelete` (não `nullOnDelete`), então
  hoje não é possível excluir um usuário que já comentou/agiu em algo;
  não mexido nesta fase, só registrado aqui.

## Notificações (WhatsApp via Z-API)

- **`App\Notifications\Channels\ZApiChannel`**: canal customizado de
  notification, incluído no array de `via()` como classe
  (`ZApiChannel::class`, não uma string registrada) — Laravel resolve
  isso automaticamente via `class_exists()` no `ChannelManager`, sem
  precisar registrar em lugar nenhum. A Notification precisa implementar
  `toWhatsApp($notifiable): string` (igual a `toMail`/`toArray`); sem
  esse método, sem `ZAPI_INSTANCE_ID`/`ZAPI_TOKEN` configurados
  (`config('services.zapi.*')`, vazio por padrão), ou sem
  `$notifiable->routeNotificationFor('whatsapp')` resolver um número —
  o canal só retorna cedo, nunca lança exceção (best-effort, canal não
  crítico). **Reaproveita o campo `telefone`** já existente no `User`
  (decisão da Fase 9 do roadmap de maturidade SaaS — não criar um campo
  `whatsapp` duplicado) via `User::routeNotificationForWhatsapp()`.
  Primeira notification ligada nesse canal:
  `AlertaPrazoSuprimentoNotification`. Número é normalizado (só dígitos
  + DDI 55 se ausente) antes de enviar pro endpoint
  `https://api.z-api.io/instances/{id}/token/{token}/send-text`.
  **Testar o canal em isolamento** — chamar
  `(new ZApiChannel())->send($notifiable, $notification)` direto, nunca
  `$user->notify()`/`notifyNow()` — porque a notification também vai por
  `broadcast`, que tentaria alcançar o Reverb de verdade mesmo em teste
  (indisponível no container de teste) e derrubaria o teste por um
  motivo alheio ao canal WhatsApp. Fase bloqueada em conta Z-API real
  pra teste de ponta a ponta — testado até aqui só com `Http::fake()`.

## Benchmarking entre obras

- **`⚡benchmarking-obras.blade.php`** (rota `gestao.benchmarking`, slug
  `gestao.benchmarking` no `CatalogoFuncionalidades`, `ESCOPO_TENANT` —
  mesmo motivo de `engenharia.pacotes`: página com seletor de várias
  obras ao mesmo tempo, não faz sentido travada na obra ativa da sessão)
  compara indicadores lado a lado, uma coluna por obra do tenant. **Não
  recalcula nada novo** — só reagrega fórmulas já provadas em outras
  telas, reparametrizadas por obra num loop: PPC histórico (mesma query
  de `ppcQuery()` em `⚡relatorios-restricoes.blade.php`, sem quebra por
  semana), % avanço atual (mesma fonte que `resumoAderencia()` do
  Dashboard usa — última semana com `Realizado` gravado na curva raiz do
  último Report **emitido**, nunca `CurvaAvanco` ao vivo — filosofia de
  "Report é fotografia"), restrições em aberto por Pilar Lean e tempo
  médio de resolução (mesmas fórmulas de `porPilar()`/
  `tempoMedioResolucao()`). Todo indicador nulo (sem dado suficiente)
  renderiza como "—", nunca zero ou erro.

## Papéis, permissões e clientes

- **Perfis de acesso são personalizáveis por tenant** (`App\Models\Perfil`,
  nome livre, por tenant) e concedem permissões **ação por ação** (ver/
  criar/editar/excluir) sobre um catálogo fixo de páginas do sistema
  (`App\Support\CatalogoFuncionalidades` — muda só quando o código muda,
  não é tabela). A concessão em si (quais ações um perfil tem em cada
  página) é dinâmica: `App\Models\PerfilPermissao` (presença de linha =
  permissão concedida). Perfil continua vinculado **por obra** via
  `obra_user.perfil_id` (não é único por usuário no tenant — o mesmo
  usuário pode ter perfis diferentes em obras diferentes). Enforce via
  Policies nativas, consultando `App\Models\Concerns\HasObraPapel::
  temPermissaoNaObra()` (obra-scoped) ou `temPermissaoEmAlgumaObraDoTenant()`
  (páginas tenant-level, ex.: Cadastros). **NÃO usar spatie/laravel-permission.**
- **Só quem criou o tenant define perfis/permissões** (`User::
  podeGerenciarTenant()`, Gate `gerenciar-perfis-acesso`, tela dedicada
  em "Perfis de Acesso") — nenhum perfil pode se autoconceder esse poder.
- **5 perfis padrão são semeados automaticamente** na criação de todo
  tenant (`Tenant::booted()` → `Perfil::seedPadrao()`), com
  `slug_padrao` estável (`admin`|`gerente_planejamento`|`engenheiro`|
  `encarregado`|`cliente_leitura`) que sobrevive a renomeações — é como o
  código localiza "o perfil equivalente a X deste tenant" (ex.:
  auto-atribuição do criador de obra como Gerente de Planejamento). O
  enum legado `App\Enums\Papel` continua existindo só como referência
  desses 5 slugs, não é mais consultado pelas Policies.
- **Cliente (ex: Vopak) tem acesso somente leitura**: é um usuário com o
  perfil equivalente a `cliente_leitura` na obra (só `ver` em tudo),
  criado DENTRO do tenant da construtora. Federação cross-organização
  (cliente com tenant próprio) é v2.

## Importação de cronograma

- Atividades vêm de duas origens (`origem`): `ms_project` (importadas do
  cronograma .xml/MSPDI) e `manual`. A **reimportação nunca altera nem
  apaga atividades manuais**.
- Reconciliação por `external_uid` (o UID do MS Project).
- **IMPORTANTE:** atividade que some de uma reimportação é ARQUIVADA
  (`fora_do_cronograma = true`), nunca apagada — preserva as restrições.
- Toda importação passa por uma PRÉVIA (criar/atualizar/arquivar) que o
  usuário confirma; o parsing roda em job de fila.
- A importação também captura os dados de avanço (HH faseados em semanal e
  mensal) que alimentam as curvas S. Séries: previsto=Baseline Work,
  tendência=Work atual, realizado=Actual Work. Cada importação é um snapshot
  em `avanco_periodos`.
- **IMPORTANTE — duas responsabilidades de importação, separadas por
  `App\Enums\TipoCronogramaImportacao`** (`baseline`|`avanco`|`ambos`, coluna
  `tipo` em `cronograma_importacoes`, `ambos` é o valor legado/default pra
  registros anteriores a esta separação): a importação da seção OBRA
  (`⚡cronograma.blade.php`) grava só **Previsto** e é a única que
  cria/atualiza/arquiva Atividade/PacoteTrabalho; a importação da seção
  Relatórios (`⚡relatorio-importar-avanco.blade.php`) grava só
  **Realizado/Tendência** e **nunca cria nem arquiva** — só faz `update()`
  (nunca `updateOrCreate()`) num conjunto restrito de campos de progresso em
  atividades já casadas por `external_uid`; UID sem atividade correspondente
  vira "ignorada" na prévia. `CurvaAvanco`/`ReportGerador` resolvem "última
  importação" filtrando por tipo elegível pra cada série (nunca escolhem uma
  importação do tipo errado como fallback). No assistente de Report, o
  usuário escolhe as duas importações (Linha de Base e Avanço)
  independentemente.
- **HH (regras validadas, não alterar sem reteste):** contar só atribuições a
  recurso de Trabalho (Resource Type=1); o Value faseado é DURAÇÃO, não
  minutos; distribuir pelo PONTO MÉDIO do bloco.
- **Desvio é assumido e transparente:** a reconstrução tem desvio de fronteira
  <~0,3%/mês; totais fecham exatos. Mostrar aviso na prévia da importação e
  selo nas curvas. Nunca esconder o desvio.
- **Edição manual:** `curva_ajustes` permite o usuário cravar o valor exato do
  MS Project por período. O valor calculado nunca é destruído; células
  ajustadas têm marcador e tooltip com o original.
- **Campos customizados Texto20-30 → classificação da Atividade:** mapeamento
  fixo do template da empresa, lido de `Atividade.textos` (JSON bruto) via
  `App\Support\TextoCustomizado::valor($textos, N)` (aceita `Texto{N}`/
  `Text{N}`, PT/EN) e classificado em `App\Imports\MsProjectImporter::
  aplicar()`:
  `Texto20`=Etapa · `Texto21`=Disciplina · `Texto22`=FrenteTrabalho ·
  `Texto23`=`Atividade.faturamento_direto` (booleano, 'sim'→true) ·
  `Texto24`=Entregável · `Texto25`=EquipeResponsavel (NÃO é o mesmo que
  `Atividade.responsavel_id`, que é usuário interno cadastrado — Equipe/
  Responsável é texto livre do MS Project, pode ser terceirizada) ·
  `Texto26`-`Texto30`=Personalizado1-5. Etapa/Disciplina/FrenteTrabalho/
  Entregável/EquipeResponsavel/Personalizado1-5 são tabelas de lookup por
  obra (exceto Disciplina, por tenant), criadas automaticamente via
  `firstOrCreate` na importação — reimportação sem o campo NUNCA apaga
  classificação manual já feita (só grava quando o XML traz valor
  não-vazio). Todos os 11 campos são filtro nas páginas de cronograma
  (Lookahead, Plano Semanal, Restrições, Relatórios de Restrições, Linhas
  de Base, Suprimentos, Curvas S) — exceto Lista de Documentos, que não
  tem relação com Atividade. `Texto23` substituiu o antigo checkbox
  "Somente terceirizados" do Lookahead/Plano Semanal (que lia
  `whereRaw`/`JSON_EXTRACT` cru do JSON) — mesma semântica, agora exposta
  como coluna e filtro Sim/Não/Todos de verdade. Em Curvas S, os ajustes
  manuais (`curva_ajustes`) também são escopados pelos 8 campos novos —
  como 16 colunas cruas no índice único estouram o limite de 3072 bytes
  do MySQL, as 8 novas entram via `escopo_extra_hash` (MD5, calculado
  sozinho em `CurvaAjuste::booted()`), nunca setado à mão.

## Convenções

- Nomes de domínio (tabelas, colunas, models de negócio) em **português**:
  `nome`, `obra`, `cliente`, `restricao`, `prazo_limite`, `caminho_critico`.
- Código PHP, classes utilitárias e variáveis em inglês.
- Soft deletes + autoria nas tabelas de planejamento.
- Categorias de restrição configuráveis por tenant, mapeadas a 1 dos 5
  pilares Lean (campo `pilar_lean`).
- `caminho_critico` é flag manual no MVP (não há cálculo de CPM).
- **Relatórios de Restrições** (`⚡relatorios-restricoes.blade.php`, slug
  `restricoes.relatorios`, seção Restrições do menu): página só de
  leitura de analytics pro gestor da obra — 7 indicadores (por
  responsável, por período com filtro de data/granularidade, atrasadas,
  tempo médio de resolução, prontidão por disciplina, por pilar/categoria,
  card compacto de risco alto P×I com link pra Matriz completa). O filtro
  de período usa `prazo_limite` por padrão (campo prospectivo — "previstas"
  = data limite futura), com toggle pra `aberta_em`. Sem migration nova:
  tudo é computado a partir de `restricoes`/`categorias_restricao`/
  `atividade_itens_prontidao`/`atividades`. Export Excel usa
  `WithMultipleSheets` (terreno diferente de `RestricoesExport`, que é
  linha-a-linha) — uma aba por indicador.

## Testes

**YOU MUST** manter `tests/Feature/TenantIsolationTest.php` passando.
Rode `php artisan test` antes de considerar qualquer tarefa concluída.

## Infraestrutura de release (Fase 1 do roadmap de maturidade SaaS)

- **Repositório Git**: primeiro commit em 2026-07-20 (`dcfengenharia/dcf-radar`,
  privado, GitHub). `.env` está no `.gitignore` (raiz) e
  `bootstrap/cache/.gitignore` protege o cache compilado — nunca comitar
  esses dois.
- **Monitoramento de erro — Sentry** (`sentry/sentry-laravel`): integrado
  via canal de log (`config/logging.php` → canal `sentry`, incluído no
  `stack`), não via `app/Exceptions/Handler.php` (que continua só com o
  `render()` customizado de 403). Configurar em produção preenchendo
  `SENTRY_LARAVEL_DSN` no `.env` — vazio = desabilitado, sem erro nenhum.
  `send_default_pii` fica `false` (não manda IP/dados de request por
  padrão — decisão consciente de LGPD).
- **Backup automático — spatie/laravel-backup**: agendado em
  `app/Console/Kernel.php` (`backup:run --only-db` às 03:00,
  `backup:clean` às 04:00). **Só banco de dados, nunca arquivos da
  aplicação** — decisão deliberada pra não arriscar um `.env` real
  (segredos) dentro de um zip de backup por engano; o código-fonte já
  está seguro no Git. Destino inicial: disk `local`
  (`storage/app/{APP_NAME}/*.zip`, já coberto pelo `.gitignore` de
  `storage/`); trocar pra disk `s3` em `config/backup.php` quando as
  credenciais AWS forem preenchidas em produção (`config/filesystems.php`
  já tem o disk `s3` pronto).
- **CI — GitHub Actions** (`.github/workflows/tests.yml`): roda
  `php artisan test` a cada push/PR pra `main`, com serviço MySQL efêmero
  e build de assets (`npm ci && npm run production`, necessário porque
  algumas views usam o helper `mix()`).
- **Ambiente de execução real é o WSL2** (`~/dcf_eng` dentro da distro
  Ubuntu, ver memória `project_ambiente_dev_docker`) — `C:\dcf_eng` (onde
  as ferramentas de edição desta sessão operam) precisa ser sincronizado
  manualmente pro WSL depois de cada edição antes de testar/rodar. As
  duas árvores foram reconciliadas em 2026-07-20 (pequenas divergências
  de UI que existiam só num lado ou só no outro); a partir daqui, manter
  as duas em sincronia a cada mudança.

## Como trabalhar neste repositório

- O projeto já está em andamento: **audite antes de alterar** e adeque o
  que existe ao alvo, em vez de recriar do zero.
- Para mudanças de schema, proponha um plano em Plan Mode e espere
  aprovação explícita. Nunca edite migrations já aplicadas em produção.
- Forma-alvo de referência em `/referencia/dcf-radar`.
- Decisões e roadmap completos em `docs/BRIEFING-DCF-RADAR.md`.
