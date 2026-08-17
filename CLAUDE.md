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
- **`AtividadeItemProntidao.concluido_por`/`concluido_em`**: quem marcou um
  item de prontidão como concluído e quando — gravado/limpo pelos dois
  pontos que fazem esse toggle (`marcarItemProntidao()` na lista e
  `marcarItemNaDetalhe()` no popup de detalhe da atividade, ambos em
  `⚡restricoes.blade.php`), sempre via `Auth::id()`/`now()` quando o
  valor vai pra `true`, e `null`/`null` quando volta pra `false` (nunca
  fica um autor "fantasma" apontando pra uma marcação já desfeita). O
  popup de detalhe exibe isso logo abaixo do item concluído (nome +
  data/hora). **Datas de início/término do popup** deixaram de ser
  `inicio_planejado`/`data_termino` (ao vivo) — viraram Linha de Base
  (`baseline_inicio`/`baseline_termino`, mesma fonte "ao vivo" que o
  Lookahead usa sem seletor explícito) e Tendência (snapshot da última
  importação de Avanço/Ambos da obra, mesmo filtro de tipo do
  Lookahead/`CurvaAvanco::resolverImportacaoId()` — `N/A` quando a obra
  não tem nenhuma), cada bloco rotulado com a data da importação sendo
  seguida. O antigo indicador "Situação" (dias restantes/em atraso,
  calculado a partir de `data_termino`) foi removido — decisão do
  usuário, informação redundante com as datas agora exibidas.
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
- **Arquivo de cronograma maior que o limite do plano** (`⚡cronograma
  .blade.php`, Importar Cronograma): nunca mostra o erro genérico
  "não pode ser superior a N kilobytes" do Livewire — mostra um aviso
  amigável com o tamanho do arquivo e o limite do plano, mais uma
  chamada pra ação. Dois pontos alimentam a MESMA propriedade
  `$excedeuLimiteUpload` (única fonte de verdade pro alerta em Blade):
  o evento JS `livewire-upload-error` (caminho real do usuário — o
  upload falha no próprio endpoint do Livewire, travado pelo
  `DefinirLimiteUploadDoTenant`, ANTES do arquivo virar
  `$arquivoTemp` de verdade — chama `$wire.marcarLimiteUploadExcedido()`
  com o tamanho capturado no `x-on:change`) e o catch de
  `ValidationException` dentro de `analisar()` (defesa em profundidade
  pro caso do componente já ter `$arquivoTemp` setado, ex.: testes via
  `Livewire::test()`, que pulam o endpoint de upload). O CTA
  ("Faça upgrade do plano") só aparece pra quem `podeGerenciarTenant()`
  (criador do tenant) — quem não pode vê "peça ao administrador da
  conta" em vez do link, mesmo idioma de mensagem já usado em
  `createWork()`/`ConviteController`.
- **`StatusAssinatura::concedeAcesso()`** (`Trial`/`Ativa` = true,
  `Cancelada`/`Suspensa`/`Inadimplente` = false) é enforced por
  `App\Http\Middleware\EnsureTenantAssinaturaAtiva` (alias
  `assinatura.ativa`, no grupo de rotas `app`) — admin da plataforma
  (`is_platform_admin`) sempre passa; tenant sem nenhuma `Assinatura`
  também sempre passa (mesmo fallback "sem plano = sem restrição" usado
  em todo o resto do sistema).

## Área do administrador da plataforma

- **Área visualmente exclusiva**: `/admin/**` usa `resources/views/layouts/layoutAdmin.blade.php`
  (não `layoutMaster`/`contentNavbarLayout`) com seu próprio menu lateral,
  `resources/views/layouts/sections/menu/adminVerticalMenu.blade.php` —
  filtra o MESMO `$menuData[0]->menu` compartilhado por
  `App\Providers\MenuServiceProvider` pelos itens com
  `gate === 'acessar-admin-plataforma'` (achatando o submenu de
  "Painel do Proprietário" em links de primeiro nível, já que não sobra
  nada do app do tenant pra agrupar ali dentro) — nenhum JSON duplicado,
  editar `resources/menu/verticalMenu.json` continua sendo o único
  lugar pra adicionar uma página nova em `/admin`. Reaproveita
  navbar/footer normais via uma flag nova opcional,
  `$hideEmpresaSwitcher` (mesmo padrão de `$navbarFull`/
  `$navbarHideToggle` já existentes em `navbar.blade.php`), que esconde
  o dropdown "Empresa Ativa"/"Cadastrar Nova Empresa" — não faz sentido
  pro dono da plataforma.
- **Login redireciona direto pra lá**: `AuthenticatedSessionController::
  store()` — único handler de login realmente usado (Fortify instalado
  mas suas próprias rotas de login não são as registradas) — checa
  `Auth::user()->is_platform_admin` logo após autenticar e manda pra
  `route('admin.dashboard')` em vez do `RouteServiceProvider::HOME`
  (`/app/home`) de sempre.
- **`tenants.eh_conta_operadora`**: a empresa dona da própria plataforma
  (ex.: DCF.eng) nunca deveria ter sido tratada como "mais um cliente
  assinante" — antes dessa fase, virar admin exigia passar pelo wizard
  público de `/register` (criar uma "construtora" fake), ganhando trial
  automático e entrando nas métricas de negócio como qualquer tenant
  real. Agora `Tenant::criarTrialAutomatico()` pula a criação da
  `Assinatura` fake pra tenant com essa flag (`if ($this->
  eh_conta_operadora) return;`, primeira linha do método), e
  `Tenant::scopeClientes()` (`->where('eh_conta_operadora', false)`) é
  o ÚNICO ponto de exclusão reaproveitado no dashboard admin
  (`Tenant::clientes()->count()`, e a query-base de
  `assinaturasAtuais()` — de que `assinaturasAtivasCount()`/
  `assinaturasPorStatus()`/`assinaturasPorPlano()`/`mrrAproximado()`
  todos derivam, então filtrar uma vez ali propaga sozinho). A conta
  operadora continua **visível** na lista de tenants (não escondida —
  senão o admin acha que sumiu), só com badge `bg-label-dark` "Conta
  Operadora" (mesmo idiom das badges de origem/método já usadas em
  `⚡tenants/⚡show.blade.php`, Fase 10.9). Botão "Marcar/Desmarcar Conta
  Operadora" no detalhe do tenant (`⚡tenants/⚡show.blade.php`) é
  autoatendimento deliberado — nunca decidimos por conta própria qual
  tenant existente é a operadora, o próprio admin marca com um clique.
- **Bootstrap sem passar pelo `/register` público**: `app:seed-owner`
  (`App\Console\Commands\SeedOwnerCommand`) agora CRIA o tenant
  operadora (`eh_conta_operadora = true`, nome via `--empresa=`) quando
  nenhum `--tenant=` é passado e nenhum tenant existe — antes exigia um
  tenant pré-existente e mandava "registre-se pelo UI". **Achado desta
  fase**: `email_verified_at` não está em `User::$fillable`
  (propositalmente, protegido de mass-assignment em código de
  request) — o `updateOrCreate()` deste comando vinha descartando esse
  campo em silêncio há tempos, deixando todo admin criado por
  `app:seed-owner` travado na tela de verificação de e-mail no primeiro
  login real. Corrigido com `forceFill()` isolado logo depois do
  `updateOrCreate()` — seguro aqui porque é comando de CLI restrito
  (dev/staging), nunca input de request.
- **"Acesso a tudo da plataforma" continua sendo impersonation**
  (`App\Support\ImpersonationContext::start()`, botão "Entrar como" no
  detalhe do tenant) — decisão deliberada de não duplicar cada tela do
  tenant como versão somente-leitura cross-tenant dentro do `/admin`.
  **Exceção deliberada**: Gestão de Usuários (abaixo) É uma visão
  cross-tenant de verdade dentro do `/admin` — porque `User` nunca usa
  `BelongsToTenant` (ver a nota logo acima, seção de isolamento), listar
  todos os usuários não exige nenhum truque de scope, só uma query
  Eloquent comum.
- **Gestão de Usuários (`/admin/usuarios`)**: lista TODOS os usuários de
  TODOS os tenants numa tabela só (`User::with('tenant')->query()`, sem
  `TenantContext::actingAs()` — não precisa, `User` não tem scope) com
  busca, filtro por conta e por status. Botão "Desativar"/"Reativar"
  alterna a coluna nova `users.ativo` (boolean, default `true`) — usuário
  inativo não é setado à mão em nenhum outro lugar do sistema além dessa
  tela. Bloqueio em runtime é
  `App\Http\Middleware\BloquearUsuarioInativo`, registrado GLOBALMENTE no
  grupo `web` do `Kernel.php` (mesmo padrão de
  `DefinirLimiteUploadDoTenant`, que já vive ali) — cobre `/app`, `/admin`
  e qualquer rota autenticada futura automaticamente, sem precisar
  lembrar de anexar a cada grupo de rota novo. Como o guard de sessão do
  Laravel resolve `auth()->user()` do banco a cada requisição, isso já
  desloga uma sessão já aberta no primeiro request seguinte à
  desativação — não precisa de nenhuma invalidação de sessão especial
  além do `logout()` feito ali dentro. Sem exceção pra
  `is_platform_admin`: um admin desativado por outro admin também é
  barrado (a trava real contra lockout é a auto-desativação bloqueada em
  `alternarStatus()`, não o middleware). **Achado desta fase**: a
  checagem usa `auth()->user()->ativo === false` (comparação estrita),
  NUNCA `! auth()->user()->ativo` — mesmo fallback "dado ausente nunca
  bloqueia" já usado em `EnsureTenantAssinaturaAtiva`/`limiteObras()`.
  Descoberto porque `$this->actingAs($user)` nos testes reaproveita o
  MESMO objeto `User` em memória do factory pra toda a duração do teste
  (nunca refaz o SELECT que um request real faria) — um factory sem
  `'ativo'` explícito deixa esse atributo simplesmente ausente do array
  em memória, então `! null` avaliava como bloqueio numa suíte inteira de
  testes de rota HTTP sem relação nenhuma com essa feature (`! $ativo`
  quebrou 76 testes espalhados por vários arquivos). A coluna legada `users.status`
  (string solta do starter-kit Jetstream, nunca esteve em `$fillable`,
  nunca foi lida em lugar nenhum) NÃO foi reaproveitada de propósito —
  `ativo` é uma coluna nova e dedicada, mesmo idioma simples já usado em
  `eh_conta_operadora`.

## Página de cadastro (registro)

- **`resources/views/auth/register.blade.php`** é um wizard de 3-4 passos
  (Acesso → Empresa → [Plano] → Revisão) — **front-end puro**: um único
  `<form action="{{ route('register') }}" method="POST">`, JS vanilla
  inline só troca `display`/pills entre `.wizard-step[data-step]` e roda
  `reportValidity()` por passo antes de avançar. Nenhum campo é
  `disabled` nos passos escondidos (só `d-none`), então o POST final
  envia tudo de uma vez — **zero mudança de contrato** com
  `RegisteredUserController::store()`/`CreateNewUser::create()`, que
  continuam sendo o único ponto real de validação (client-side é só UX).
- **Passo "Escolha seu plano" é condicional**: `RegisteredUserController::
  create()` passa `$planosAtivos = Plano::where('ativo', true)-
  >orderBy('preco_mensal')->get()` pra view; o passo inteiro (e a pill
  correspondente) some do array `$steps` quando `$planosAtivos` está
  vazio — cadastro nunca fica bloqueado por falta de Plano cadastrado
  (mesmo fallback "sem plano = sem restrição" de sempre). Com 1 único
  plano ativo, ele vem pré-selecionado; com 2+, o clique no card seta um
  `<input type="hidden" name="plano_id">`.
- **`Tenant::comPlanoTrialForcado(?$planoId, $callback)`**: mesmo padrão
  de propriedade estática + `try/finally` de
  `App\Support\TenantContext::actingAs()`. Permite ao registro (e só a
  quem chamar explicitamente) forçar qual `Plano` o trial automático de
  7 dias usa, em vez do `padrao_trial` genérico resolvido por
  `criarTrialAutomatico()` — todo outro chamador de `Tenant::create()`
  (admin, modal "Criar Nova Empresa", testes, seeders) não é afetado.
  `plano_id` inválido/inativo no request nem chega aqui: é rejeitado
  antes pela validação (`Rule::exists('planos', 'id')->where('ativo',
  true)`).
- **Campos novos, ambos opcionais**: `cnpj` (mascarado em JS puro no
  evento `input`, sem lib — `00.000.000/0000-00`) e `razao_social`,
  gravados direto no `Tenant` fillable já existente (nenhuma migration
  nova). Nome dos campos de sempre (`first_name`/`last_name`/
  `company_name`/`email`/`password`/`password_confirmation`/`terms`) e o
  toggle de mostrar/ocultar senha (`form-password-toggle`, `bx-hide`, JS
  global do template) foram preservados sem alteração.

## Cobrança (Mercado Pago)

- **Self-service, 3 métodos de pagamento, sem SDK oficial** — sai de
  "admin cria Assinatura na mão" (fluxo manual em `⚡admin/tenants/
  show.blade.php::salvarAssinatura()`, que continua existindo intacto)
  pra cobrança real iniciada pelo próprio tenant em
  `⚡empresa/assinatura.blade.php` (rota `app.empresa.assinatura`,
  linkada por um card em "Dados da Empresa"). **Cartão** usa a API de
  Assinaturas do Mercado Pago (`/preapproval`) — cobrança recorrente
  automática de verdade, tokenizada no navegador via **Card Payment
  Brick** (SDK `https://sdk.mercadopago.com/js/v2`, carregado só nessa
  página, mesmo padrão *per-page* do Chart.js — dado de cartão nunca
  toca o servidor). **Pix/Boleto não têm recorrência nativa no Mercado
  Pago** — a cada ciclo o `App\Services\CobrancaScheduler` gera uma
  cobrança avulsa nova (`/v1/payments`) alguns dias antes do vencimento.
  `App\Services\MercadoPagoGateway` centraliza as chamadas — mesmo
  padrão de `App\Notifications\Channels\ZApiChannel` (Fase 9): `Http::`
  facade direto, sem SDK do pacote, 100% testável com `Http::fake()`.
  Config em `services.mercadopago.*` (`MERCADOPAGO_ACCESS_TOKEN`/
  `_PUBLIC_KEY`/`_WEBHOOK_SECRET`, vazios por padrão) — diferente do
  Z-API, `MercadoPagoGateway` **lança exceção** sem credencial (nunca
  falha em silêncio, porque aqui é o mecanismo de cobrança em si, não
  um canal de notificação lateral).
- **Trial automático de 7 dias**: hook em `Tenant::booted()`, junto do
  `Perfil::seedPadrao()` já existente — só cria a `Assinatura` se
  existir um `Plano` marcado `padrao_trial = true`; sem plano marcado,
  tenant novo nasce sem Assinatura (mesmo fallback "sem plano = sem
  restrição" de sempre). `origem = 'sistema'` — nem `manual` (admin não
  fez nada) nem `mercadopago` (não passou por pagamento nenhum ainda).
- **Webhook** (`App\Http\Controllers\MercadoPagoWebhookController`,
  rota pública `POST /webhooks/mercadopago`, fora de `auth`, exceção
  própria em `VerifyCsrfToken::$except`): valida a assinatura HMAC do
  header `x-signature` com `webhook_secret` antes de processar qualquer
  coisa, e **nunca confia no payload do webhook pra decidir status** —
  sempre reconfirma direto na API via `MercadoPagoGateway::buscarPagamento()`/
  `buscarAssinatura()`, seguindo a recomendação de segurança do próprio
  Mercado Pago. Resolve o tenant via `DB::table()` cru (visitante não
  autenticado, mesmo padrão do `ClienteRelatorioPublicoController` da
  Fase 7) e entra nele com `TenantContext::actingAs()`. Sempre responde
  200 rápido, mesmo em erro interno (só `report()`) — evita loop de
  retentativa do Mercado Pago.
- **`assinaturas`/`assinatura_faturas`/`users` são as ÚNICAS tabelas do
  projeto sem `BelongsToTenant`** (`User` nunca teve o trait — achado ao
  construir a Gestão de Usuários do admin, ver seção "Área do
  administrador da plataforma"; mesmo motivo de `Assinatura` já
  documentado: o dono da plataforma precisa ver todos os tenants ao
  mesmo tempo) — **toda consulta feita a partir de uma tela do TENANT
  precisa filtrar
  `tenant_id` manualmente**, nunca confiar em scope automático (risco
  coberto por teste dedicado em `TenantIsolationTest.php`). `origem` da
  `Assinatura` tem 3 valores possíveis: `manual` (admin), `sistema`
  (trial automático) ou `mercadopago` (self-service, com
  `metodo_pagamento` preenchido) — badges correspondentes no histórico
  de `⚡admin/tenants/show.blade.php`.
- **Inadimplência automática** — antes só existia manual (admin marcava
  `Inadimplente` na mão). Agora `CobrancaScheduler::verificarInadimplenciaSeNecessario()`,
  chamado pelo comando diário `assinaturas:processar` (`dailyAt('07:00')`,
  depois dos outros 3 já agendados): Trial vencido, ou Ativa cujo ciclo
  venceu há mais de 3 dias de carência sem fatura paga correspondente
  (dá tempo de um Pix/Boleto recém-gerado compensar) — vira
  `Inadimplente`, já enforçado de verdade desde a Fase 5
  (`concedeAcesso()`/`EnsureTenantAssinaturaAtiva`). Uniforme pros 3
  métodos: cartão com cobrança recorrente rejeitada também deixa
  `renovar_em` estagnado (o webhook só avança essa data quando o
  pagamento é de fato confirmado), caindo no mesmo caminho de
  Pix/Boleto sem tratamento especial.
- **Notificações**: `FaturaGeradaNotification`, `PagamentoConfirmadoNotification`,
  `AssinaturaInadimplenteNotification` — mesmo formato de 4 canais
  (mail+database+broadcast+`ZApiChannel::class`) já estabelecido na
  Fase 9, reaproveitando 100% da infraestrutura pronta.
- **Bloqueado em conta Mercado Pago real** pra teste de ponta a ponta
  (tokenização de cartão de verdade via Brick, webhook de verdade) —
  mesma ressalva da Fase 9 com a Z-API. Tudo testável e testado com
  `Http::fake()`.

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

## Detalhe da Obra — Gantt do Cronograma

- **Aba "Cronograma" de `⚡obra-detalhe.blade.php`** ganhou um Gantt de
  verdade com hierarquia expansível/recolhível nativa — **dhtmlxGantt
  Community (v10, MIT)**, não ApexCharts (tentativa anterior só desenhava
  barras soltas, sem árvore EAP de verdade nem expandir/recolher — trocado
  a pedido do usuário). Biblioteca vendorizada como qualquer outro lib do
  tema: fonte em `resources/assets/vendor/libs/dhtmlx-gantt/`
  (`dhtmlxgantt.js` + `dhtmlxgantt.scss`, este último é o `.css` original
  só renomeado — o glob do `webpack.mix.js` só pega `.scss` em
  `vendor/libs/**`, não `.css` cru), compilada por `npm run production`
  igual aos demais, carregada só nessa página via `@section('vendor-style'
  /'vendor-script')` (mesmo padrão *per-page* do Chart.js/ApexCharts).
  **Antes de trocar por qualquer lib "grátis"**: só a edição Community
  (pacote npm `dhtmlx-gantt`, license file MIT) serve pra um SaaS fechado
  — a antiga edição "Standard" da dhtmlx era GPLv2 (copyleft, exigiria
  abrir o código da aplicação); confirmado lendo o `LICENSE.md` de dentro
  do pacote antes de vendorizar, não só o campo `license` do `npm view`.
- **Pacotes viram linha `type=project`, atividades viram `type=task`**
  (`App\Models\PacoteTrabalho`→`parent_id` mapeado direto pro `parent` do
  dhtmlx) — o expandir/recolher e a indentação da árvore são 100% nativos
  da lib (ícone `.gantt_tree_icon`), nada de Alpine/`wire:key` customizado
  como no Lookahead. Data/duração do pacote = união (mín. início/máx.
  término) dos próprios descendentes, **calculada em PHP**
  (`dadosGantt()`), nunca inferida ao vivo pelo dhtmlx — bate exatamente
  com a Linha de Base gravada. Só entram na árvore pacotes que têm alguma
  atividade com Linha de Base (direta ou em descendente) — nunca mostra
  pacote vazio só por existir cadastrado. Datas vêm de
  `baseline_inicio`/`baseline_termino` (Linha de Base "ao vivo", mesma
  fonte que o Lookahead usa sem seletor explícito) — nunca
  `inicio_planejado`/`data_termino` ao vivo, que representam tendência,
  não a estrutura originalmente importada. Cor da barra por
  `task.color` nativo do dhtmlx: vermelho (`#ff4d49`) pra
  `caminho_critico = true`, azul (`#696cff`) pras demais (barras de
  pacote/projeto usam a cor padrão da lib, não são recoloridas). Teto de
  1000 atividades (`LIMITE_GANTT`, bem mais folgado que o antigo limite
  de 200 do ApexCharts — dhtmlx tem scroll virtual nativo e aguenta
  volume real de projeto) com aviso + link pra Linhas de Base quando a
  obra tem mais.
- **Troca de aba não usa `@if` Blade dentro do `@script`** (mesmo gotcha já
  documentado noutras páginas: `@script` do Livewire roda só uma vez, não
  reexecuta por request) — `setAba('cronograma')` despacha o evento
  `cronograma-tab-ativada` com os dados já serializados
  (`$this->dadosGantt`, formato `{data: [...], links: []}` do dhtmlx), e o
  JS ouve via `$wire.on(...)`. Como o `<div>` do gráfico está dentro do
  bloco condicional da aba (some/reaparece do DOM a cada troca), o JS
  sempre chama `gantt.init(el)` + `gantt.clearAll()` + `gantt.parse(...)`
  de novo a cada evento — a instância do `gantt` é um singleton global da
  lib (sem `destroy()` como o ApexCharts), então reinit é o padrão correto
  pra remontar num container que pode ter mudado.
- **Escala mensal + seletores de Linha de Base e Tendência** (`⚡obra-
  detalhe.blade.php`, mesmo componente): `gantt.config.scales` com 2
  níveis (`year`/`month`, mês em PT-BR via array fixo `mesesPt` — dhtmlx
  não tem i18n de mês embutido) substituiu a escala diária original —
  Gantt de cronograma real (meses/anos) nunca precisa granularidade de
  dia. Dois `<select>` novos (`linhaBaseId`/`tendenciaImportacaoId`,
  `wire:model.live`) espelham o padrão já usado no Lookahead: sem
  seleção, Linha de Base é a "ao vivo" (`baseline_inicio`/
  `baseline_termino` direto da Atividade) e Tendência é a importação de
  Avanço/Ambos mais recente (`importacoesDisponiveis()`, mesmo filtro por
  `tipo` de `CurvaAvanco::resolverImportacaoId()`); com uma Linha de Base
  selecionada, as datas (inclusive as barras) passam a vir do
  `AtividadeSnapshot` daquela importação, não mais da Atividade ao vivo.
  `ganttAtividades()` **não filtra mais por baseline no SQL** (removido
  o `whereNotNull`) — com Linha de Base selecionada, quem decide "tem
  dado pra mostrar" é a resolução via snapshot dentro de `dadosGantt()`,
  não mais a query; o card vazio agora checa `empty($this->dadosGantt
  ['data'])`, nunca `$this->ganttAtividades->isEmpty()`.
- **Colunas novas na grade** (Início/Término LB, Início/Término Tend.,
  todas dd/mm/aa via helper JS `formatarDataBR()`). Linha de pacote
  (`type=project`) nunca mostra Tendência (`task.type === 'project' ?
  '' : ...` nos templates de coluna) — só a atividade folha tem
  tendência de verdade. **Achado desta fase — closure presa em config
  configurada uma única vez**: `gantt.config.columns`/`gantt.templates
  .tooltip_text` são setados só na PRIMEIRA chamada (dentro do guard
  `if (!ganttConfigurado)`, prática normal do dhtmlx); qualquer dado que
  os templates de coluna precisem ler a cada evento (aqui, `ganttData
  .temTendencia`, pra decidir entre `—`/`N/A`) **não pode vir capturado
  por closure direto do primeiro disparo** — ficaria congelado no valor
  da primeira renderização pra sempre, nunca reagindo a troca de filtro
  depois. Corrigido com uma variável mutável fora do bloco de config
  (`let temTendenciaAtual`), reatribuída em TODO disparo do evento
  `cronograma-tab-ativada` — os templates leem essa variável externa,
  nunca o `ganttData` capturado na definição. Regra geral: em qualquer
  config de lib configurada uma única vez (guard tipo `if
  (!xConfigurado)`), nenhum template/callback dela pode capturar dado
  por closure direto do evento que disparou a config — só variável
  mutável externa, reatribuída a cada evento.
- **Segunda barra (Tendência) sobreposta à barra principal (Linha de
  Base)** — duas tentativas documentadas aqui falharam por serem recurso
  Pro mesmo na edição MIT/Community vendorizada: (1) `gantt.addTaskLayer()`
  é literalmente apagado do objeto `gantt` dentro do próprio `init()` —
  achado inspecionando o `dhtmlxgantt.js` vendorizado com Node fora do
  browser (`function bo(e){delete e.addTaskLayer,delete
  e.addLinkLayer}`, chamada logo depois do mixin que registra os dois
  métodos); (2) "split task" (`render:'split'` no pai + um "filho"
  sintético com `parent` apontando pra própria atividade) É reconhecido
  internamente pela lib (`gantt.isSplitTask()`/`task.$split_subtask`
  batem certinho, inclusive com `gantt.config.open_split_tasks=false`
  testado), mas nenhum elemento chega a ser desenhado no DOM — mesmo
  resultado nas duas tentativas: funciona nos bastidores, mas o
  RENDER visual de uma segunda barra é que é a parte restrita.
  **Solução que funciona de verdade**: hook no evento público
  `onGanttRender` (dispara a cada `gantt.render()`/re-parse, sem
  restrição nenhuma) que remove overlays antigos e, pra cada tarefa
  visível com `inicio_tend`/`termino_tend`, calcula a geometria via
  `gantt.getTaskPosition(task)` (barra da Linha de Base) e
  `gantt.getTaskPosition(task, inicioTend, fimTendExclusivo)` (barra da
  Tendência) — ambos métodos públicos e sem restrição — e injeta um
  `<div class="gantt-tendencia-bar">` absoluto direto em
  `gantt.$task_data` (mesmo elemento-contêiner que o próprio código-
  fonte do `addTaskLayer` original usa como destino padrão, achado
  lendo o texto-fonte: `defaultContainer(){if(e.$task_data)return
  e.$task_data;...}`) — nenhuma API removida/gateada envolvida, só DOM
  manipulation sobre um container público. `row_height`/`task_height`
  ajustados (34px/16px) pra sobrar espaço visível abaixo da barra
  principal pra essa barra fina (6px, amber `#ffab00`) não ficar
  cortada. Terceiro item na legenda ("Tendência") ao lado de "Linha de
  Base"/"Linha de Base — Caminho Crítico" já existentes.
- **Ordem da árvore segue o cronograma importado, não a data de baseline**
  — achado em QA manual pelo usuário: a primeira versão ordenava
  `ganttAtividades()` por `orderBy('baseline_inicio')`, então pacotes e
  atividades apareciam na ordem das datas, não na ordem real do MS
  Project (ex.: atividade "2.2" com baseline mais cedo aparecia antes da
  "2.1"). Corrigido reaproveitando o MESMO algoritmo de
  `⚡linhas-base.blade.php::arvoreAtividades()` (convenção do projeto: um
  comparador por arquivo, não compartilhado via trait) dentro de
  `dadosGantt()`: `compararCodigos()` (ordena `codigo`/`codigo_cronograma`
  segmento a segmento como números — "2" antes de "10", nunca comparação
  de string crua) pra pacotes-irmãos e `compararOrdemAtividade()`
  (`ordem_manual` quando definida em AMBOS os lados > `codigo_cronograma`
  > `inicio_planejado`/nome como último fallback) pra atividades dentro
  do mesmo pacote, mais a intercalação de nível raiz (pacotes raiz +
  atividades órfãs COM `codigo_cronograma`, numa única sequência ordenada
  por código — nunca joga órfãs sempre no fim). `ganttAtividades()` não
  usa mais `orderBy('baseline_inicio')` (virou `orderBy('id')`, só pra
  determinismo do teto `LIMITE_GANTT` em EAPs muito grandes) — a ordem
  visual real é 100% derivada em PHP por esse algoritmo, a ordem de
  fetch do banco não importa mais.

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
- **Lookahead — seletor de Tendência só lista importações de Avanço/Ambos**:
  `importacoesDisponiveis()` (`⚡lookahead.blade.php`) filtra
  `CronogramaImportacao` por `tipo` (mesmo filtro de
  `CurvaAvanco::resolverImportacaoId()`) — nunca uma importação
  Baseline pura, que é escopo exclusivo do seletor de Linha de Base.
  Sem seleção explícita, usa a mais recente elegível (`orderByDesc
  ('importado_em')`); com mais de uma Linha de Base salva, idem via
  `linhaBaseSelecionada`. **Obra sem nenhuma importação de Avanço**
  (`temImportacaoAvanco()` false): colunas "Início"/"Término"
  (tendência) mostram `N/A` na tabela e nos 3 exports (PDF, PDF em
  árvore, Excel) — nunca caem pros campos ao vivo da Atividade
  (`inicio_planejado`/`data_termino`), que na real refletem só a
  última importação de Linha de Base (ver nota de arquitetura logo
  acima), nunca uma de Avanço de verdade. **Decisão do usuário**: o
  filtro de janela (30/60/90 dias) continua funcionando mesmo nesse
  caso — quando a fonte escolhida é "Tendência" e não há importação de
  Avanço, o filtro cai sozinho pra Linha de Base (`$inicioTend ??
  $inicioBase`), pra não esconder a obra inteira só por nunca ter
  passado por Importar Avanço; a coluna exibida continua `N/A`.
  **Achado desta fase**: os botões "Colapsar por nível" (`@click=
  "colapsarAteNivel(nv)"`, dentro de um `@foreach ($niveisExistentes)`)
  ficavam sem efeito depois que a quantidade de níveis da EAP mudava
  entre renders (filtro, nova atividade mais profunda) — faltava
  `wire:key` no botão, então o morph do Livewire perdia a associação
  entre o `<button>` e o nível certo, e o clique real parava de
  disparar `colapsarAteNivel()` com o argumento certo (chamar o método
  Alpine direto continuava funcionando, só o clique real quebrava —
  foi assim que o bug foi isolado). Regra geral: todo `@foreach` que
  renderiza elemento com listener Alpine (`@click` etc.) precisa de
  `wire:key` estável, mesmo fora de uma lista de "itens de domínio"
  óbvia.

## Programação Semanal — fechamento e revisões

- **`programacoes_semanais.status`** (`App\Enums\StatusProgramacaoSemanal`,
  `Aberta`|`Fechada`) — antes deste feature, o "congelamento" da
  Programação Semanal (docs em [[project_programacao_semanal_congelada]])
  era só um efeito colateral automático de comprometer atividades. Agora
  existe uma ação EXPLÍCITA do usuário, botão "Gerar Programação" no
  Plano Semanal (`fecharProgramacao()`) e também em Minhas Programações
  — chama `App\Actions\ProgramacaoSemanal\FecharProgramacaoSemanal`, que
  seta `status = Fechada`, `fechada_em`, `fechada_por`. **Depois de
  fechada, o único campo editável continua sendo o de sempre** —
  marcar atividade concluída/não concluída (`Atividade.status`, via
  `AtividadeObserver`) — decisão deliberada de NÃO duplicar um campo de
  status dentro de `programacao_semanal_itens`; "realizado" continua
  sendo lido ao vivo da própria Atividade, igual já era antes.
- **Duas travas distintas, não confundir**: `semanaEstaCongelada`
  (`⚡plano-semanal.blade.php`) é a trava TOTAL automática legada —
  existe header E a semana já é passado — bloqueia até marcar
  concluída; continua intacta pra qualquer semana que nunca passou pelo
  botão novo (decisão consciente: não fazer backfill retroativo).
  `semanaEstaFechada` é o status explícito novo — abre a EXCEÇÃO de
  marcar concluída/não concluído mesmo com `semanaEstaCongelada=true`.
  Guard final em `marcarConcluida()`/`confirmarNaoConcluido()`:
  `semanaEstaCongelada && ! semanaEstaFechada`. Comprometer atividades
  novas (`comprometerSelecionadas()`) continua bloqueado nos dois casos:
  `semanaEstaCongelada || semanaEstaFechada`.
- **`ProgramacaoSemanal::ativaPara(Work $obra, string $semanaInicio)`**
  é o ÚNICO ponto de resolução de "qual é a programação vigente desta
  semana" (`orderByDesc('versao')->first()`) — substitui todo lookup
  direto por `obra_id`+`semana_inicio`, tanto em
  `programacaoDaSemana` (Plano Semanal) quanto dentro de
  `RegistrarComprometimentoSemanal`. Necessário porque agora pode haver
  mais de uma linha por semana: o unique de `programacoes_semanais`
  virou `(obra_id, semana_inicio, versao)` (antes era só
  `obra_id`+`semana_inicio`).
- **Revisões** (`versao`, `revisao_de_id` self-FK): só é possível criar
  uma revisão de uma programação Fechada que também seja a versão MAIS
  RECENTE daquela semana (histórico fica linear, nunca ramifica —
  `App\Actions\ProgramacaoSemanal\CriarRevisaoProgramacaoSemanal`). A
  nova versão nasce Aberta, com as MESMAS atividades da original, mas
  com datas/HH **RE-CAPTURADOS ao vivo** da Atividade no momento da
  revisão (decisão do usuário — não copia os valores congelados
  antigos, já que replanejamento parte de onde o cronograma está agora).
  A versão original NUNCA é alterada, fica visível pra sempre no
  histórico. `App\Actions\ProgramacaoSemanal\ProgramacaoSemanalSnapshot::
  linhasParaItens()` é o helper compartilhado entre
  `RegistrarComprometimentoSemanal` e `CriarRevisaoProgramacaoSemanal`
  pra não duplicar a lógica de captura de HH/datas.
  `RegistrarComprometimentoSemanal` agora lança `RuntimeException` se
  tentarem comprometer atividade numa programação já Fechada (é preciso
  criar uma revisão primeiro).
- **% de Aderência** (`ProgramacaoSemanal::aderencia()`): itens da
  programação cuja Atividade está `Concluido` (lida ao vivo) ÷ total de
  itens — `null` sem itens (nunca zero, mesmo idioma de "traço" já
  usado na coluna "% do Projeto"). É basicamente o PPC da própria
  Programação Semanal específica, não o PPC ao vivo do período.
- **Nova tela Minhas Programações** (`radar.programacoes`, slug
  `restricoes.minhas_programacoes`): uma linha por semana, sempre a
  versão vigente (`unique('semana_inicio')` sobre a listagem já
  ordenada por `semana_inicio desc, versao desc`), com % de aderência,
  status, versão e ações de Gerar Programação/Criar Revisão. "Ver
  detalhe" linka pro Plano Semanal daquela semana via `#[Url(as:
  'semana')]` novo em `⚡plano-semanal.blade.php::semanaInicio`
  (`?semana=YYYY-MM-DD`), normalizado pro início da semana no `mount()`.
- **Achado — Alpine (`recolhidos`, estado de colapsar/expandir pacote)
  preso na semana antiga ao navegar**: reportado como "o filtro de
  período não funciona" — o backend filtrava certinho a cada
  `semanaSeguinte()`/`semanAnterior()` (PHP recalcula `arvoreAtividades()`
  do zero a cada request), mas o `<div>` que declara `x-data="{
  recolhidos: [...] }"` não tinha `wire:key`, então o Livewire nunca
  recriava esse escopo Alpine entre renders — um pacote recolhido numa
  semana (mesmo `id` de pacote, é a mesma EAP da obra inteira)
  continuava "recolhido" na semana seguinte, escondendo TODAS as
  atividades daquela semana atrás de um `x-show` que nunca reabre
  sozinho. Corrigido com `wire:key="arvore-{{ md5(...pluck('id')
  ->implode(',')) }}"` no `<div>` — muda sempre que o CONJUNTO de linhas
  visíveis muda (troca de semana OU de filtro), forçando o Alpine a
  reiniciar do zero (`recolhidos: []`). Regra geral (mesma classe de bug
  já documentada alhures neste arquivo): qualquer `x-data` que guarda
  estado derivado de uma lista renderizada pelo Livewire precisa de
  `wire:key` amarrado ao CONTEÚDO dessa lista, senão o estado sobrevive
  a trocas de dados que deveriam invalidá-lo.

## Popup de confirmação genérico (substitui o confirm() nativo do navegador)

- **`resources/views/components/confirmacao-acao.blade.php`**: modal
  Bootstrap único, registrado UMA VEZ nos dois layouts
  (`contentNavbarLayout.blade.php`/`layoutAdmin.blade.php`, dentro de
  `@persist('confirmacao-acao')` — mesmo motivo já documentado em
  `radar-loading.blade.php`), que substitui **todos** os `wire:confirm`
  do sistema (o alerta genérico `confirm()` do navegador). Qualquer botão
  que antes fazia `wire:click="metodo(args)" wire:confirm="mensagem"`
  agora faz só `onclick="confirmarAcao(this, { mensagem, metodo, args,
  corBotao, icone })"` — a função global (definida no componente)
  resolve o componente Livewire mais próximo do botão clicado via
  `btn.closest('[wire\\:id]')` + `Livewire.find(id)`, guarda a ação
  pendente, popula o modal (título/ícone/mensagem/cor do botão) e só
  chama `component.call(metodo, ...args)` de fato quando o usuário clica
  em "Confirmar" — cancelar simplesmente fecha o modal sem chamar nada.
  Extraído como componente reutilizável (em vez de 27 modais bespoke
  copiados) porque a alternativa — duplicar handler+modal em cada uma
  das ~27 telas que usavam `wire:confirm` — seria massivo e frágil;
  esse componente único cobre TODAS elas.
- **Botões com `wire:click.stop`** (ex.: linha de tabela clicável por
  trás) viram `onclick="event.stopPropagation(); confirmarAcao(...)"` —
  o `event` implícito do atributo inline substitui o `.stop` do wire.
- **Mensagens condicionais** (ex.: "Desativar"/"Reativar" usuário,
  "Marcar"/"Desmarcar" Conta Operadora) continuam resolvidas em Blade
  (`{{ $cond ? 'A' : 'B' }}`) dentro do literal JS passado pro
  `confirmarAcao(...)` — nenhuma lógica nova no JS, só interpolação.
- **Duas exceções deliberadas, NÃO convertidas**: o botão "Emitir" de
  `⚡relatorio-detalhe.blade.php` já tinha ganho um modal bespoke próprio
  antes deste componente existir (primeira iteração, pedida
  isoladamente) — mantido como está, não fazia sentido duplicar. Os
  modais de exclusão de Obra (`⚡obras/index.blade.php`,
  `workDeleteModal`/`workBulkDeleteModal`) e o "Confirmar Programação"/
  "Não Cumprimento" de `⚡plano-semanal.blade.php` já eram modais
  bespoke com estado próprio (nome da obra a excluir, contagem de
  selecionadas, textarea de causa) — não usavam `wire:confirm` pra
  início de conversa, então ficaram de fora do escopo desta troca.

## Report Semanal — correção de emissão + indicadores de Restrições/Engenharia/Suprimentos

- **Bug de emissão corrigido**: `Report::emitir()` chamava
  `Notification::send($destinatarios, new ReportEmitidoNotification(...))`
  fora de qualquer try/catch — como a notification implementa
  `ShouldQueue`, `Notification::send()` já dispara o push pra fila
  DENTRO da própria requisição HTTP; só o PROCESSAMENTO do canal
  (mail/database/broadcast) acontece depois, no worker. Investigação
  encontrou um defeito real e reproduzível nesse processamento
  assíncrono: `ReportEmitidoNotification` usa o canal `broadcast`, e o
  worker (rodando dentro do container) tenta falar com o Reverb via
  `localhost:8080` — hostname que só resolve pro Reverb a partir do
  NAVEGADOR (JS), nunca de dentro da rede Docker (precisaria ser
  `reverb:8080`). Isso derruba o job silenciosamente
  (`Illuminate\Broadcasting\BroadcastException`, visível em
  `failed_jobs`), nunca como erro explícito na tela — **não foi possível
  reproduzir ao vivo um erro síncrono na tela só clicando em "Emitir"**
  (testado com Redis acessível e 2+ usuários na obra, sem exception
  nenhuma na resposta HTTP). Mesmo sem confirmar 100% que essa é a causa
  exata do sintoma original relatado, a correção aplicada cobre
  qualquer falha de infraestrutura de fila/broadcast: `Notification::
  send(...)` agora vive dentro de um `try/catch (\Throwable $e) {
  report($e); }` em `Report::emitir()` — a transição de status
  (`update([...])`, sempre ANTES do bloco de notificação) nunca mais
  fica refém de uma falha nesse efeito colateral. **Ponto de atenção
  em aberto**: se o sintoma original persistir, o próximo passo é
  corrigir a configuração do Reverb pro worker resolver o host correto
  (`REVERB_HOST`/broadcasting config), não mais o código de `emitir()`.
- **`report_indicadores_semana`** (`App\Models\ReportIndicadorSemana`,
  `BelongsToTenant`, `belongsTo(Report::class)`): tabela genérica (uma
  linha por `categoria` × `janela`, não 3 tabelas dedicadas — mesmo
  espírito de `report_curva_datapoints`) que grava os indicadores de
  Restrições/Engenharia/Suprimentos da semana anterior e da próxima
  semana, relativos ao `periodo_referencia` do Report. Mesma filosofia
  de fotografia do resto do Report: `ReportGerador::
  gerarIndicadoresSemana()` (chamado dentro da mesma transação de
  `gerarRascunho()`, logo após o loop de curvas) calcula e grava UMA
  ÚNICA VEZ — nada num Report já criado volta a consultar
  Restricao/DocumentoEngenharia/ItemSuprimento ao vivo depois (ex.:
  resolver uma restrição depois do report gerado não muda o indicador
  já gravado). `janela` = `semana_anterior` (previsto × concluído,
  `total_concluido` sempre preenchido) ou `semana_proxima` (só previsto,
  `total_concluido` sempre `null` — não é possível "concluir" algo que
  ainda não aconteceu). `detalhes` (json) grava as linhas individuais já
  formatadas pra exibição (nomes/status como STRING congelada, nunca
  FK viva). Datas de janela SEMPRE derivadas de `periodo_referencia`
  (`->copy()->subWeek()`/`->addWeek()`), nunca de `now()` — mesmo report
  reaberto meses depois mostra a mesma janela relativa de sempre.
  Critério de "concluída" em Restrições da semana anterior: resolvida
  **dentro do prazo** (`resolvida_em <= prazo_limite`) — mesmo critério
  já usado em `ppcPorSemana()` (`⚡relatorios-restricoes.blade.php`). Em
  Suprimentos, "previsto"/"realizado" usam a data da **última etapa** de
  cada item (`$item->etapas->last()`), por ser o que é efetivamente
  comparável entre as duas séries. Cada uma das 6 combinações
  categoria×janela grava uma linha SEMPRE, mesmo com `total_previsto=0`
  e `detalhes=[]` — é o que permite a view distinguir "sem dado" (mostra
  mensagem amigável) de "não gerado ainda" (reports antigos, gerados
  antes desta feature, não têm nenhuma linha em `indicadoresSemana` —
  o mesmo `?? null` no Blade cai pro mesmo estado vazio, então reports
  legados continuam abrindo normalmente).
- **View**: partial compartilhado `resources/views/pages/radar/
  _partials/relatorio-indicadores-semana.blade.php` (incluído via
  `@include`, não Blade component — convenção já usada por
  `relatorio-tabela-curva`/`relatorio-grafico-config` neste mesmo
  diretório), reaproveitado 6x em `⚡relatorio-detalhe.blade.php`: bloco
  "Desempenho da Semana Anterior" logo após o aviso de precisão do XML
  e antes do `@foreach` de curvas; bloco "Planejamento da Próxima
  Semana" logo depois do `@endforeach`, antes da Galeria de Fotos — os
  cards de curva existentes ficam 100% intocados no meio dos dois
  blocos novos (decisão de risco mínimo). Duas propriedades
  `#[Computed]` novas no componente (`indicadoresSemanaAnterior()`/
  `indicadoresSemanaProxima()`) indexam `$report->indicadoresSemana` por
  `categoria` pra lookup direto no Blade. **Achado desta fase**:
  `ReportGerador::gerarIndicadorRestricoes()` tentava exibir o pilar Lean
  da restrição via `$r->categoria->pilar_lean->label()` — mas
  `App\Enums\PilarLean` nunca teve método `label()` (só `StatusRestricao`/
  `StatusDocumento` têm). Corrigido duplicando o mesmo `labelPilar()`
  (match expression) já usado em `⚡dashboard.blade.php`/
  `⚡benchmarking-obras.blade.php`/`⚡relatorios-restricoes.blade.php` —
  mesma convenção do projeto de não compartilhar via trait. Não pego
  pelos testes automatizados porque o teste unitário não setava
  `categoria_id` na Restrição (o operador `?->` engolia o erro em
  silêncio); só apareceu testando com dado realista no browser — reforça
  a prática de QA manual mesmo com suíte verde.
- **Rótulos de dados removidos dos gráficos**: o plugin próprio
  `pluginRotulosDados` (`resources/views/pages/radar/_partials/
  relatorio-grafico-config.blade.php`) desenhava um balão branco com o
  valor (`${valor}%`) sobre cada barra/ponto do gráfico de eixo duplo
  "Curva S" — removido por completo (junto do helper
  `desenharRetanguloArredondado()` que ele usava), assim como o registro
  `plugins: [cfg.pluginRotulosDados]` nas 2 chamadas `new Chart(...)`
  de `⚡relatorio-detalhe.blade.php`/`⚡relatorio-novo.blade.php` e numa
  TERCEIRA já existente em `⚡curvas.blade.php` (página "Curvas S", achado
  via grep depois de apagar o plugin compartilhado — não fazia parte do
  pedido original, mas precisava ser corrigido pra não quebrar aquela
  página). Título, legenda, eixos com escala/unidade e o tooltip nativo
  do Chart.js continuam intactos — só o rótulo fixo sobre o gráfico
  saiu. O velocímetro de aderência NÃO foi tocado
  (`pluginAgulha`/`pluginTextoCentral` continuam — são a agulha e o
  readout central do próprio gauge, não rótulo por ponto de dado).

## Lista de Documentos — popup de tipo de importação + cards de KPI clicáveis

- **Contexto**: pedido grande do usuário ("Gestão de Documentos de
  Engenharia — Registro Mestre, Revisões e Controle de Emissões", 32
  seções) — a investigação (3 agentes Explore + leitura direta do
  `DocumentoEngenhariaImporter`) confirmou que quase tudo já existia e
  estava testado (Ondas A-E acima: Documento≠Revisão, status na
  revisão, PDF por revisão, importação em duas fases com prévia,
  dashboard, reprogramação com histórico imutável). O único
  comportamento novo, confirmado com o usuário via
  `AskUserQuestion`, foi obrigar a escolha explícita da intenção da
  importação (só criar novos vs. só atualizar existentes) antes de
  rodar a planilha — hoje o importador sempre cria E atualiza juntos,
  silenciosamente, no mesmo arquivo.
- **`App\Imports\DocumentoEngenhariaImporter` NÃO foi alterado** —
  zero mudança em `analisar()`/`aplicar()`, mantendo 100% dos testes de
  `ImportarDocumentosEngenhariaTest.php` intactos. Toda a lógica nova
  vive só em `⚡documentos-engenharia.blade.php`, que já é quem
  orquestra as duas chamadas.
- **Passo 0 do modal de importação**: novo estado `$tipoImportacao`
  (`'novos'`|`'atualizacao'`|`null`). Antes do upload (que continua
  intocado), dois cards clicáveis (mesmo idiom `style="cursor:pointer"`
  já usado em `⚡restricoes.blade.php`, não `<button>`) — "Nova Lista de
  Documentos" e "Atualização de Status e Revisões" —
  `escolherTipoImportacao(string $tipo)` avança pro passo já existente;
  `voltarTipoImportacao()` (sem argumento, pra evitar incerteza sobre
  `wire:click="metodo(null)"`) reseta pra escolher de novo via link
  "trocar".
- **Filtro pós-prévia** (`filtrarPorTipoImportacao()`, método privado
  novo): depois da chamada já existente e intocada a
  `$importador->analisar(...)`, cruza os códigos deduplicados contra
  `DocumentoEngenharia::where('obra_id', ...)->whereIn('codigo', ...)`
  (consulta nova, só no componente) e separa `previaImportacao['linhas']`
  em aplicáveis vs `['ignorados']`: tipo=novos + código já existe →
  ignorado ("já existe — não será criado por este caminho"); tipo=
  atualizacao + código não existe → ignorado ("não encontrado — não
  será atualizado por este caminho"). `confirmarImportacao()` chama
  `$importador->aplicar(...)` **sem nenhuma mudança de assinatura** —
  só recebe o subconjunto já filtrado. Toast final soma `⚠N ignorados`
  quando aplicável.
- **Cards de KPI clicáveis** (Total/Concluídos/Aguardando/Atrasados):
  3 constantes novas em `documentosQuery()` —
  `STATUS_FILTRO_AGUARDANDO`/`_ATRASADO`/`_CONCLUIDO` — cada uma
  espelhando exatamente a mesma condição que `totais()` já usava pra
  contar esses números (nenhuma regra nova, só reaproveitada como
  filtro). `wire:click="$set('statusIdFiltro', '__valor__')"` em cada
  card; a branch existente de "status ID explícito" em
  `documentosQuery()` passou a excluir esses 4 pseudo-valores
  (`STATUS_FILTRO_NAO_EMITIDO` já existia) pra nunca tentar casá-los
  como FK real.
- **Link "Visualizar PDF"**: modal de revisões ganhou um segundo ícone
  (`target="_blank"`, reaproveitando o mesmo `$rev->anexoUrl()`) ao lado
  do download já existente — sem PDF, mostra `—` como antes.
- **Não tocado** (regra fundamental do pedido, seguida à risca):
  `DocumentoEngenhariaImporter`, `DocumentoEngenharia`/
  `DocumentoEngenhariaRevisao`/`StatusDocumento`/
  `DocumentoEngenhariaReprogramacao` (models e migrations),
  `PacoteEngenharia`, permissões (`engenharia.pacotes`), rota, menu.
- Testes novos: 3 em `ImportarDocumentosEngenhariaTest.php` (tipo=novos
  ignora código existente; tipo=atualizacao ignora código inexistente;
  tipo=atualizacao aplica só os códigos existentes) + 1 em
  `DocumentosEngenhariaPageTest.php` (cada card de KPI aplica o filtro
  correspondente). Suíte completa: 916 passed / 6 skipped (skips
  pré-existentes, sem relação com esta feature).

## E-mail de boas-vindas ao verificar o cadastro

- **Disparo**: `App\Listeners\EnviarBoasVindasAposVerificarEmail`,
  registrado em `App\Providers\EventServiceProvider::$listen` pro evento
  nativo `Illuminate\Auth\Events\Verified` (ao lado da entrada já
  existente de `Registered::class => [SendEmailVerificationNotification::class]`
  — `shouldDiscoverEvents()` retorna `false` neste projeto, então todo
  listener precisa entrar explicitamente nesse array). O próprio
  `VerifyEmailController::__invoke()` (não alterado) já disparava
  `event(new Verified(...))` só quando `markEmailAsVerified()` retorna
  true — usuário já verificado que acessa o link de novo cai no
  `return` cedo e não dispara o evento de novo, então o e-mail de
  boas-vindas nunca é reenviado à toa.
- **`App\Notifications\BoasVindasNotification`**: mesmo esqueleto de
  `AgradecimentoFeedbackNotification` (sem parâmetro de construtor,
  `via() => ['mail']`, `ShouldQueue` + conexão dedicada `redis`,
  `MailMessage` simples com `greeting()/line()/action()/salutation()`,
  sem Markdown customizado) — mensagem com um pitch curto das 3
  frentes centrais do produto (Lookahead/EAP, Restrições/prontidão,
  Relatórios/PPC) e botão de ação pro Painel de Controle
  (`url('/app/home')`).
- Testes: `tests/Feature/BoasVindasNotificationTest.php` — verifica
  email verificado com sucesso dispara a notificação, hash inválido
  não dispara, e usuário já verificado que acessa o link de novo
  também não dispara (idempotência via `Verified` só disparar uma
  vez). Suíte completa: 919 passed / 6 skipped (skips pré-existentes),
  sem regressão nos testes de verificação de e-mail já existentes
  (`tests/Feature/Auth/EmailVerificationTest.php`,
  `tests/Feature/EmailVerificationTest.php`, que usam `Event::fake()`
  e por isso não exercitam o listener novo).

## Lookahead — Curva S da Atividade no popup de detalhe

- **Contexto**: pedido do usuário pra substituir os 4 cards do popup de
  detalhe da atividade (achados na investigação como placeholder puro
  do tema — "On route vehicles" etc., sem nenhuma ligação com dado
  real) por uma Curva S Previsto×Realizado da própria atividade, com
  seletor de Baseline, %Previsto/%Realizado e indicador visual (farol).
  Regra fundamental do pedido: mudança isolada, sem alterar filtros,
  tabela, outros popups (Restrições/Prontidão/Comentários) ou qualquer
  outra tela.
- **Achado que definiu a solução**: `App\Models\AvancoPeriodo` já grava
  HH faseado **por atividade** (coluna `atividade_id` já existe, ao lado
  de `cronograma_importacao_id`/`serie`/`granularidade`/`periodo_inicio`)
  — então uma Curva S de verdade por atividade é montável **sem
  nenhuma migration nova**, só estendendo `App\Services\CurvaAvanco::calcular()`
  (que já resolve importação certa, agrupa HH por período e calcula
  %acumulado, hoje só escopado por pacote/etapa/disciplina/etc.) com
  mais um parâmetro opcional no fim da assinatura, `?string $atividadeId = null`
  — filtro direto de coluna, sem quebrar nenhuma chamada existente.
  `Atividade.percentual_concluido` (a % "ao vivo" do MS Project) foi
  descartado como fonte pra isso — é um valor único sobrescrito a cada
  importação, sem histórico por baseline.
- **`⚡lookahead.blade.php`**: novo estado `$modalBaselineId` (seleção de
  Baseline só do popup — independente do `$linhaBaseId` da página, que
  continua "ao vivo" por padrão como sempre foi), resetado a cada
  `verAtividade()`. Novo computed `modalCurvaAtividade()`:
  - **Previsto**: `CurvaAvanco::calcular(..., linhaBaseId: $baselineEfetivo, atividadeId: $at->id)`
    — `$baselineEfetivo` = `$modalBaselineId` ou a `LinhaBase` mais
    recente da obra (reaproveita `$this->linhasBase`, computed já
    existente na página).
  - **Realizado**: vem do `cronograma_importacao_id` gravado no ÚLTIMO
    Report **emitido** da obra (`Report::where('status', Emitido)->latest('periodo_referencia')->first()`,
    mesmo padrão de `⚡dashboard.blade.php::ultimoReportEmitido()`) — nunca
    da importação de avanço mais recente "ao vivo" (isso é o que
    garante "Report é fotografia": trocar a Baseline no popup NUNCA
    muda o Realizado, só o Previsto). Rebaseado com
    `CurvaAvanco::rebasearPercentual()` (já existente, mesmo mecanismo
    usado no Report semanal) contra o total de HH do Previsto, pra as
    duas séries ficarem na MESMA escala de %.
  - `%Previsto`/`%Realizado`/indicador do resumo são lidos dos ÚLTIMOS
    pontos dessas MESMAS séries (nunca um cálculo paralelo) — Previsto =
    último ponto com `periodo_inicio <= hoje`; Realizado = último ponto
    da série (mesmo critério de "última semana com Realizado" já usado
    no Dashboard/Benchmarking). Indicador: `neutro` sem Realizado,
    `desfavoravel` se Previsto > Realizado, `favoravel` caso contrário.
- **Chart.js**: página não carregava a lib — adicionado
  `@section('vendor-script')` em `resources/views/app/radar/lookahead.blade.php`
  (mesmo path/convenção do Report). Init do gráfico via `x-init` do
  Alpine dentro de um `<div wire:key="curva-atividade-{{ $modalAtividadeId }}-{{ $baselineId }}-...">`
  — **não** `@script`/`$wire.on` (que só roda uma vez por componente,
  regra já documentada neste arquivo) — o `wire:key` novo a cada
  troca de atividade/baseline força o Livewire a destruir/recriar o nó,
  reexecutando o `x-init` com dado fresco via `@js($curvaAtividade)`.
  Cores reaproveitadas de `relatorio-grafico-config.blade.php` (Previsto
  `#3C79E8`, Realizado `#71dd37`). Rótulos das duas séries são a UNIÃO
  ordenada dos períodos de ambas (nunca só os do Previsto) — senão
  Previsto e Realizado com semanas diferentes ficam desalinhados no
  eixo X.
- **Mensagens amigáveis** (nunca erro, nunca 0% forçado): sem nenhuma
  `LinhaBase` na obra → "Nenhuma Baseline disponível para esta
  atividade" (Realizado continua aparecendo se houver Report); sem
  Report emitido → "Realizado ainda não disponível..." + indicador
  neutro; sem HH suficiente pra essa atividade na baseline escolhida →
  "Não há dados suficientes para gerar a Curva S desta atividade".
- **Não tocado**: filtros da página, tabela principal, export PDF/Excel/impressão,
  demais seções do popup (Restrições/Prontidão/Comentários), qualquer
  outra tela que usa `CurvaAvanco::calcular()` (parâmetro novo é opcional).
- Testes: 4 novos em `tests/Feature/LookaheadTest.php` (baseline+report
  completo; troca de baseline muda Previsto mas não o HH bruto do
  Realizado — a % dele muda de escala porque é sempre rebaseada contra
  o total da baseline selecionada, mesma lógica de sempre; sem Report
  emitido; sem Baseline). Suíte completa: 923 passed / 6 skipped (skips
  pré-existentes). QA manual no browser confirmou o gráfico renderizando
  com dado real (Previsto 40%→100%, Realizado 30%→lacuna, sem erro no
  console) e os números do resumo batendo com a curva.
- **Bug achado em QA pelo usuário (corrigido na mesma fase)**: dava pra
  ver "% Previsto: 23%" ao lado de uma data de início no FUTURO — sem
  sentido. Causa: "Início/Término (Linha de Base)" exibidos no popup
  vinham do campo AO VIVO da atividade (`$at->baseline_inicio`), enquanto
  o %Previsto vinha da Baseline SELECIONADA no seletor da Curva S — duas
  fontes diferentes, podendo divergir sempre que a baseline selecionada
  não for a mesma que definiu o campo ao vivo (ex.: reimportação mais
  recente atualizou o campo ao vivo, mas o usuário está olhando uma
  Linha de Base salva mais antiga). Corrigido resolvendo essas datas via
  `AtividadeSnapshot` da MESMA baseline selecionada (mesmo padrão já
  usado em `⚡linhas-base.blade.php`), com fallback pro campo ao vivo só
  quando não existe snapshot pra essa atividade+importação — agora data
  exibida e %Previsto sempre concordam (mesma fonte). Teste de regressão
  dedicado: `test_datas_e_percentual_previsto_vem_da_mesma_baseline_selecionada_nao_do_campo_ao_vivo`.
- **Layout ajustado a pedido do usuário**: as duas linhas separadas
  (datas / %Previsto+%Realizado+farol) viraram UMA linha só — 7 blocos
  (`col-6 col-md-3`/`col-md-4 col-lg`, responsivo) na mesma
  `div.row.g-3.text-center`.
- **Escala mensal no gráfico (a pedido do usuário)**: novo `<select>`
  "Escala" (Semanal/Mensal) no cabeçalho do card, ao lado do seletor de
  Baseline — novo estado `public string $modalGranularidade = 'semanal'`
  (reset pra `'semanal'` a cada `verAtividade()`, mesmo padrão de
  `$modalBaselineId`) + `updatedModalGranularidade()` invalidando o
  computed. Dentro de `modalCurvaAtividade()`, `GranularidadePeriodo::from($this->modalGranularidade)`
  substituiu o `GranularidadePeriodo::Semanal` até então hardcoded nas
  duas chamadas de `CurvaAvanco::calcular()` (Previsto e Realizado) —
  o parâmetro já existia no serviço, só a chamada estava fixa. **Rótulos
  reaproveitam o padrão "MÊS/AA" já usado em
  `⚡curvas.blade.php::dadosGraficoCurvaS()`/`⚡dashboard.blade.php::formatarPeriodoPt()`**
  — duplicado aqui (não compartilhado via trait, mesma convenção do
  projeto) como uma constante `private const MESES_PT` + método privado
  `formatarLabelPeriodoAtividade()` (mensal → `"JAN/26"`; semanal →
  `"Sem dd/mm/aaaa"`, mantendo o formato semanal já existente). Os rótulos
  formatados vão num mapa `labels` (chave = `periodo_inicio`, a mesma
  usada pra casar Previsto/Realizado) dentro do array retornado pelo
  computed; o JS do `x-init` faz `dados.labels[p] ?? p` só na hora de
  montar o array de labels do Chart.js — o casamento Previsto×Realizado
  por período continua 100% pela chave crua, o formato é só de exibição.
  `wire:key` do container do gráfico ganhou `{{ $modalGranularidade }}`
  (mesmo mecanismo de destruir/recriar o nó já usado pra baseline/atividade).
  Teste novo: `test_escala_mensal_formata_rotulos_como_mes_barra_ano`
  (cria import/LinhaBase/AvancoPeriodo mensal próprios, sem tocar no
  helper compartilhado `criarLinhaBaseComPrevisto()`, que continua
  semanal-only pros 5 testes que já dependiam disso). Suíte completa:
  56/56 em `LookaheadTest`, 925 passed / 6 skipped no total. QA manual
  no browser confirmou labels `ABR/26`/`MAI/26`/`JUN/26` no modo Mensal
  (Previsto acumulado 31,25%/68,75%/100%, batendo com HH 50/60/50
  seedados) e o modo Semanal inalterado (`Sem dd/mm/aaaa`), sem erro no
  console em nenhum dos dois.

## Health Check — Fase 2A (dados estruturais do cronograma)

- **Contexto**: primeira etapa da Fase 2 do Health Check (análise
  estrutural/lógica da rede do cronograma — predecessoras, tipos de
  relacionamento, lag, folgas, restrições de data). Fase 2A é
  **só leitura/captura de dado** — nenhuma regra nova de Health Check foi
  implementada (Fase 2B, ainda não iniciada, é quem vai consumir esses
  campos). Total de regras do Health Check continua 24 (as 23 originais
  + DATE-007 da rodada de ajuste da Fase 1); nenhuma categoria nova
  (Estrutura/Lógica/Folgas ficam reservadas pra Fase 2B).
- **Achado que definiu a implementação**: o parser do `MsProjectImporter`
  já faz um parse de subárvore completo por `<Task>` via `SimpleXMLElement`
  (`simplexml_load_string($reader->readOuterXML())`) — os campos
  `PredecessorLink`/`TotalSlack`/`FreeSlack`/`ConstraintType`/
  `ConstraintDate`/`Active` já estavam disponíveis em memória em `$el`
  pra cada tarefa, sem precisar mudar a estratégia de leitura do XML.
  Mudança ficou restrita a: estender o array literal `$tarefasRaw[$uid]`
  com as novas chaves, dois helpers privados novos
  (`parsearPredecessoras()`/`intOuNulo()`), e estender a chamada
  `new TarefaImportada(...)` em `montarTarefa()` — **zero mudança** em
  HH/baseline/realizado/tendência/distribuição por ponto médio/
  reconciliação/snapshots/`aplicar()`.
- **`App\DTOs\PredecessoraLink`** (readonly, novo): `predecessoraUid`,
  `tipo` (`?TipoRelacionamentoPredecessora`), `tipoCodigoOriginal` (int
  bruto do XML, preservado mesmo quando `tipo` não mapeia — nunca
  descartado), `linkLag`, `lagFormat` (ambos brutos, sem conversão de
  unidade). `App\DTOs\TarefaImportada` ganhou 7 propriedades novas, TODAS
  com default seguro (`predecessoras: []`, `totalSlack/freeSlack: null`,
  `tipoRestricao: null`, `tipoRestricaoCodigoOriginal: null`,
  `dataRestricao: null`, `ativa: true`) — aditivo, não quebra nenhum
  ponto de construção existente do DTO (inclusive nos testes antigos que
  constroem `TarefaImportada` com argumentos nomeados fixos).
- **`App\Enums\TipoRelacionamentoPredecessora`** (FF/FS/SF/SS) e
  **`App\Enums\TipoRestricaoCronograma`** (ASAP/ALAP/MSO/MFO/SNET/SNLT/
  FNET/FNLT, `imposDataFixa()` = tudo exceto ASAP/ALAP) — cada um com
  `fromCodigoMsProject(int): ?self`, retornando `null` pra código
  desconhecido em vez de inventar um tipo (mesmo princípio já usado nos
  outros enums do projeto que mapeiam código externo).
- **Decisão do usuário — sem conversão de unidade nesta fase**:
  `linkLag`/`lagFormat`/`totalSlack`/`freeSlack` são gravados EXATAMENTE
  como vêm do XML (inteiro bruto, incluindo negativos — lag negativo =
  lead, folga negativa = atraso sobre o caminho crítico). Só o SINAL
  desses valores pode alimentar regras futuras nesta fase; a conversão
  de unidade real (`LagFormat` define se o número é dias/horas/semanas
  elapsed ou não-elapsed) fica para quando houver necessidade de exibir
  valor humano-legível, e precisa ser validada contra o comportamento
  real do MS Project antes de implementada — "não converter
  silenciosamente" (decisão explícita do usuário).
- **`<Active>` ausente do XML NUNCA é tratado como inativa** — só o
  valor `'0'` explícito marca `ativa = false`; ausência do elemento ou
  qualquer outro valor mantém `ativa = true` (default). Captura é
  aditiva: nenhuma lógica de importação existente (criação/atualização/
  arquivamento de Atividade) lê ou reage a este campo ainda — reservado
  pra regras futuras da Fase 2B.
- **`Type` ausente num `<PredecessorLink>` assume FS (código 1)** — é o
  próprio default do MS Project para vínculos sem tipo explícito no XML,
  não uma invenção do importador.
- **Tarefas de resumo/projeto vão para `$plano->pacotes`, nunca
  `$plano->criar`** (comportamento pré-existente, confirmado sem
  alteração pela Fase 2A) — inclui a tarefa-raiz UID=0 do projeto.
  Marcos (`Milestone=1`) continuam em `$plano->criar`/`$plano->pacotes`
  normalmente, com `isMarco=true` e tipicamente sem predecessoras
  próprias listadas (dependendo do XML de origem).
- **Decisão explícita — sem heurística de "atividade inicial/final da
  rede"**: a futura STRUCT-001/STRUCT-002 (Fase 2B, ainda não
  implementada) vai sinalizar TODA atividade executável sem
  predecessora/sucessora, sem tentar adivinhar qual é logicamente a
  "primeira"/"última" do cronograma — decisão do usuário, evita falsos
  negativos por heurística errada.
- **Fixture**: `tests/Fixtures/cronograma_fase2a_estrutural.xml` (~28
  tarefas) cobre: predecessoras ausente/única/múltipla, os 4 tipos de
  relacionamento (FS/SS/FF/SF), lag positivo/zero/negativo/`Type`
  ausente, `TotalSlack`/`FreeSlack` positivo/zero(crítico)/negativo/
  ausente, os 8 tipos de restrição (`ConstraintType` 0-7, com
  `ConstraintDate` só nos que exigem data fixa), `Active` explícito 1/0/
  ausente, marco, e resumo/projeto (UID=0 e UID=1).
- **Testes novos**: `tests/Feature/MsProjectImporterEstruturalTest.php`
  (26 testes, chamando `analisar()` direto sem Livewire — mesma
  convenção de `CronogramaImportacaoTest.php` — nenhuma regra de Health
  Check avaliada aqui, só DTO/parser), `tests/Unit/
  TipoRelacionamentoPredecessoraTest.php` (2 testes) e `tests/Unit/
  TipoRestricaoCronogramaTest.php` (4 testes) — 32 testes novos no
  total. Suíte completa sem regressão (ver resultado da rodada em
  `docs/BRIEFING-DCF-RADAR.md` ou no relatório de conclusão da fase).
- **Não implementado nesta fase** (fica pra Fase 2B, aguardando
  aprovação): nenhuma regra STRUCT-*/LOGIC-*/SLACK-*, nenhuma conversão
  de unidade de lag/folga, nenhuma migration (dado é transiente, só
  Health Check em memória), nenhuma mudança de UI (novas categorias
  Estrutura/Lógica/Folgas ainda não aparecem na tela).

## Health Check — Fase 2B.1 (estrutura da rede de precedências)

- **Contexto**: primeira parte da Fase 2B — consome os dados estruturais
  capturados na Fase 2A pra analisar a REDE lógica do cronograma (não só
  atributos por atividade, como as 24 regras anteriores). Implementa
  SOMENTE STRUCT-001 a STRUCT-005 (sem predecessora, sem sucessora,
  isolada, rede desconectada, ciclo lógico) — LOGIC-*/SLACK-*/Score
  seguem fora de escopo, aguardando validação do usuário. Total de regras
  do Health Check: **24 (Fase 1) confirmado direto no `HealthCheckEngine`
  antes desta fase** (não 25 como o pedido original presumia) **+ 5
  (STRUCT-001..005) = 29**. Categoria nova: `Estrutura`
  (`App\Enums\HealthCheckCategoria::Estrutura`).
- **`App\Support\HealthCheck\HealthCheckGrafoCronograma`**: grafo lógico
  (predecessora → sucessora) construído **uma única vez por avaliação**
  dentro de `HealthCheckEngine::avaliar()` e reaproveitado por todas as
  regras estruturais — nenhuma delas reconstrói o grafo. Complexidade
  O(V+E) em toda parte: construção, graus (`grauEntrada()`/`grauSaida()`,
  O(1) via `count()` de um set), componentes e ciclos.
  - **Nós**: só atividades **executáveis e ativas**
    (`!isSummary && ativa`) — decisão do usuário. Tarefas-resumo/projeto
    NUNCA são nó (e relações que apontam pra elas são ignoradas, não
    contam como predecessora/sucessora de ninguém); atividades inativas
    idem. **Marcos SÃO nós normais** — decisão explícita do usuário
    (Fase 2B.1: "não quero heurísticas complexas nesta etapa"), tratados
    exatamente como qualquer atividade executável por STRUCT-001/002/003
    (um marco isolado vira STRUCT-003 igual a qualquer atividade normal
    isolada — sem tratamento especial de "marco inicial/final é sempre
    legítimo").
  - **Arestas**: direcionadas, construídas a partir de
    `TarefaImportada::$predecessoras` (Fase 2A) — só quando AMBAS as
    pontas já são nós do grafo (uma predecessora apontando pra UID
    inexistente/resumo/inativo é ignorada silenciosamente, o que faz a
    atividade aparecer com grau de entrada 0 por aquele lado, sem alerta
    específico — comportamento já documentado na Fase 2A pra
    predecessora inativa).
  - **Componentes** (`componentes()`): conectividade **NÃO direcionada**
    — decisão documentada, A→B→C é 1 componente só mesmo a direção sendo
    relevante pra predecessora/sucessora/ciclos. Union-Find (path
    compression), sem recursão.
  - **Ciclos** (`ciclos()`): componentes fortemente conexas (SCC) não
    triviais — tamanho ≥ 2, ou 1 nó com auto-laço (a tarefa lista a si
    mesma como predecessora) — via **Tarjan iterativo** (pilha explícita
    de frames `[uid, sucessoras[], índice]`, sem recursão — seguro pra
    cronogramas de milhares de atividades, onde uma versão recursiva
    arriscaria estourar a pilha de chamadas do PHP numa cadeia linear
    longa). Uma SCC pode agregar mais de um ciclo elementar entrelaçado
    (ex.: dois ciclos compartilhando um nó) — enumerar cada ciclo
    elementar separadamente teria custo potencialmente exponencial;
    agrupar por SCC é a escolha seguro-e-eficiente desta fase, documentada
    no próprio código.
- **Guarda de ruído — decisão tomada durante a implementação, não
  especificada no pedido original**: quando o grafo inteiro não tem
  **nenhuma** relação de precedência capturada
  (`HealthCheckGrafoCronograma::semNenhumaRelacaoCapturada()`, i.e.
  `totalArestas() === 0`), as regras STRUCT-001/002/003 **não avaliam
  nada** (`RegraHealthCheckEstruturalPorAtividadeBase::avaliar()` retorna
  `[]` cedo). Motivo: nenhuma fixture XML pré-existente no projeto
  (`cronograma_sample.xml`, `cronograma_health_check_sem_alertas.xml`
  etc.) tem `<PredecessorLink>` — são todas anteriores à Fase 2A. Sem
  essa guarda, TODA atividade de um arquivo sem esse campo populado
  viraria STRUCT-003 (isolada), inclusive a fixture criada
  especificamente pra não ter NENHUM alerta — inundando a análise de
  ruído e quebrando a regressão da Fase 1. Uma ausência TOTAL de dado de
  precedência num cronograma inteiro é sinal de que o arquivo de origem
  provavelmente não popula esse campo do MSPDI (comum em exportações de
  outras ferramentas convertidas pra MSPDI), não que cada atividade
  individualmente está isolada. Quando o grafo tem QUALQUER relação
  capturada (>0 arestas), a guarda não se aplica e fontes/sumidouros
  genuínos são reportados normalmente — inclusive numa obra real com
  centenas de atividades conectadas e só uma genuinamente solta.
  STRUCT-004/005 não precisam dessa guarda: com 0 arestas, todo
  componente tem tamanho 1 (filtrado por `TAMANHO_MINIMO_COMPONENTE`) e
  não há ciclo possível — o resultado correto (nenhum finding) já sai
  naturalmente do próprio algoritmo.
- **STRUCT-001 (sem predecessora)** / **STRUCT-002 (sem sucessora)**:
  `App\Support\HealthCheck\RegraHealthCheckEstruturalPorAtividadeBase`
  (novo, espelha `RegraHealthCheckBase` da Fase 1, mas itera
  `$grafo->tarefas()` em vez de `$plano->criar + atualizar`, e recebe o
  grafo já pronto). Severidade `Médio`. Não tentam adivinhar qual é "a
  primeira/última atividade lógica" — reportam TODAS as atividades sem
  predecessora/sucessora (uma obra pode ter várias frentes/pacotes
  paralelos). **Dedup com STRUCT-003**: a própria condição de
  `combina()` já exclui o caso isolado (`grauSaida() > 0` em STRUCT-001,
  `grauEntrada() > 0` em STRUCT-002) — uma atividade sem predecessora E
  sem sucessora vira só STRUCT-003, nunca as 3 juntas.
- **STRUCT-003 (isolada)**: sem predecessora E sem sucessora ao mesmo
  tempo. Severidade `Alto` (mais grave que 001/002 isoladamente).
- **STRUCT-004 (rede desconectada)**: usa `$grafo->componentes()`, mas só
  considera componentes com **2+ atividades** — componentes de 1
  atividade isolada já são STRUCT-003 e ficam de fora daqui (decisão
  tomada durante a implementação, documentada no código de
  `RedeDesconectadaRule`: evita duplicar o mesmo problema como
  "componente desconectado de tamanho 1", exatamente o tipo de ruído que
  o próprio pedido original pede pra evitar — "não gerar finding pra
  cada atividade"). Com 0 ou 1 componente qualificado, nenhum finding
  (nada pra comparar); com 2+, gera **1 finding POR componente** (nunca
  1 finding agregando todos), cada um trazendo `componente_id`,
  `quantidade_atividades`, `quantidade_relacoes` (arestas internas) e a
  lista de atividades. Severidade `Alto`.
- **STRUCT-005 (ciclo lógico)**: usa `$grafo->ciclos()`, gera **1 finding
  POR ciclo** (mesmo padrão de STRUCT-004), cada um trazendo `ciclo_id`,
  a lista de atividades envolvidas e as relações internas (arestas entre
  nós do próprio ciclo — representação "quando possível" sem precisar
  enumerar cada ciclo elementar, ver nota do algoritmo acima). Severidade
  `Crítico` — ciclo lógico impede cálculo de datas/caminho crítico/folgas
  em qualquer ferramenta de CPM, não é uma opinião, é matematicamente
  irresolvível sem quebrar o ciclo.
- **`App\Support\HealthCheck\HealthCheckRegraEstruturalInterface`**
  (novo, paralelo a `HealthCheckRuleInterface` da Fase 1 — não reaproveita
  a mesma interface): `avaliar(PlanoImportacao, HealthCheckGrafoCronograma): HealthCheckFinding[]`
  — retorna ARRAY (não `?HealthCheckFinding` nullable único) porque
  STRUCT-004/005 podem gerar mais de um finding na mesma avaliação (1 por
  componente/ciclo). `HealthCheckEngine::avaliar()` constrói o grafo uma
  vez, roda as 24 regras da Fase 1 normalmente, depois roda as regras
  estruturais e agrega tudo no mesmo `HealthCheckResultado` — a UI, os
  totais por severidade/categoria e a persistência não precisaram de
  NENHUMA mudança (tudo já era genérico o suficiente: `HealthCheckFinding`
  já aceitava `atividades: array<string, mixed>` livre, e
  `totalPorCategoria()`/`totalPorSeveridade()` já iteram
  `HealthCheckCategoria::cases()`/`HealthCheckSeveridade::cases()`
  dinamicamente).
- **`HealthCheckEngine::__construct()` ganhou um segundo parâmetro
  opcional**, `?array $regrasEstruturais = null` (mesmo padrão do
  primeiro, `$regras` — override só pra testes, produção sempre usa
  `regrasEstruturaisPadrao()`). **Achado importante desta fase**: os ~32
  testes de `HealthCheckEngineTest.php` (Fase 1) e a fixture
  `cronograma_health_check_sem_alertas.xml` NUNCA populam
  `PredecessorLink` (são anteriores à Fase 2A) — combinado com a guarda
  de "sem nenhuma relação capturada" acima, isso faz com que TODOS esses
  testes/fixtures continuem passando **sem nenhuma alteração**, porque o
  grafo deles sempre tem 0 arestas e as regras estruturais nunca disparam
  nada pra eles. `HealthCheckEngineTest.php` não precisou de nenhum
  ajuste — a guarda resolveu o conflito na raiz, não nos testes.
  `tests/Unit/HealthCheckEstruturalTest.php` (novo, 33 testes) usa
  `new HealthCheckEngine(regras: [])` pra isolar as regras estruturais
  das 24 da Fase 1 (evita uma contaminar a asserção da outra — mesmo
  espírito do override já documentado pra `$regras`).
- **Não tocado**: `MsProjectImporter.php` (Fase 2A já capturou tudo que
  esta fase precisa — nenhuma linha alterada), Score
  (`scorePreliminar()` continua `null`), severidades das 24 regras
  existentes, LOGIC-*/SLACK-* (não implementadas).
- **UI**: `resources/views/pages/radar/_partials/health-check-findings.blade.php`
  (novo) — a lista de "Ocorrências encontradas" foi **extraída como
  partial compartilhado** entre `⚡cronograma.blade.php` e
  `⚡relatorio-importar-avanco.blade.php` (que tinham blocos idênticos,
  só com `id`-prefix de collapse diferente) — decisão tomada porque a
  lógica de layout por tipo de finding cresceu o suficiente pra não valer
  mais duplicar (mesmo critério já usado em
  `relatorio-tabela-curva.blade.php`/`relatorio-grafico-config.blade.php`).
  4 layouts dentro do mesmo partial, resolvidos por `regra_id`: STRUCT-004
  (lista de componentes com contagens), STRUCT-005 (lista de ciclos +
  relações que fecham o ciclo), STRUCT-001/002/003 (tabela
  código/atividade/UID/tipo/disciplina — `disciplina` lida de
  `TarefaImportada::$textos` via `TextoCustomizado::valor(..., 21)`,
  mesmo helper já usado no importador), e o layout original da Fase 1
  (código/atividade/datas/%). O resumo por categoria/severidade
  (`$hc->totalPorCategoria()`/`totalPorSeveridade()`) já funcionou sem
  nenhuma mudança de código — só passou a mostrar "Estrutura" porque o
  enum ganhou o case novo.
- Testes: `tests/Unit/HealthCheckGrafoCronogramaTest.php` (18 testes — só
  o grafo: graus, exclusão de resumo/inativa, componentes, ciclos,
  auto-laço), `tests/Unit/HealthCheckEstruturalTest.php` (33 testes — as
  5 regras isoladas via `regras: []`, cobrindo os 31 cenários pedidos:
  STRUCT-001/002 com resumo/marco/inativa/múltiplas, dedup do
  STRUCT-003, STRUCT-004 com componentes de tamanhos variados e direções
  mistas, STRUCT-005 com ciclo simples/triplo/múltiplo/embutido num
  DAG/grafo grande sem falso positivo), `tests/Feature/
  HealthCheckEstruturalIntegrationTest.php` (8 testes — fluxo completo
  XML → `MsProjectImporter::analisar()` → `HealthCheckEngine::avaliar()`
  com o motor PADRÃO via `app(HealthCheckEngine::class)`, fixture nova
  `tests/Fixtures/cronograma_fase2b1_estrutural.xml` com 2 redes
  desconectadas, 1 atividade isolada, 1 ciclo, 1 marco isolado, 1
  atividade inativa e sua sucessora). 59 testes novos no total. Suíte
  completa: **1062 passed / 6 skipped, 0 failures** (de 1003 antes desta
  fase), incluindo `TenantIsolationTest` e toda a suíte de Fase 1/Baseline/
  Realizado-Tendência/polling/rollback sem nenhuma regressão.
- **Não avançar pra Fase 2B.2 (LOGIC-*) nem 2B.3 (SLACK-*) sem validação
  do usuário** (instrução explícita) — aguardando revisão desta etapa
  antes de continuar.

## Health Check — Fase 2B.2A (captura do modo de agendamento)

- **Contexto**: primeiro pré-requisito identificado no diagnóstico da Fase
  2B.2 (LOGIC) — toda regra de coerência de datas previstas depende de
  saber se a tarefa é *Auto Scheduled* (o MS Project recalcula as datas a
  partir da lógica de predecessoras) ou *Manually Scheduled* (as datas são
  digitadas à mão e podem legitimamente divergir de qualquer lógica/
  restrição, sem ser erro). Sem esse dado, toda regra LOGIC baseada em
  data prevista herdaria risco de falso positivo permanente — por isso
  essa captura veio ANTES de qualquer regra LOGIC, isolada em sua própria
  etapa (2B.2A), só leitura, sem regra nova nenhuma.
- **Campo investigado via WebSearch/WebFetch (não presumido)**: a
  documentação pública da Microsoft (`learn.microsoft.com/.../
  task-elements-and-xml-structure`) está travada na estrutura do schema
  de 2007 (`mspdi_pj12.xsd`) mesmo quando acessada com o parâmetro de URL
  `view=project-client-2016` — não lista nenhum campo de agendamento
  manual. A confirmação veio de duas fontes cruzadas: (1) um fórum
  (`office-forums.com`, thread "mspdi_pj14.xsd missing properties?")
  relatando que arquivos salvos pelo Project 2010 de fato contêm
  `ProjectTask.Manual`/`.ManualStart`/`.ManualFinish`/`.ManualDuration`,
  ausentes do `.xsd` publicado junto do SDK do Project 2010; (2) a classe
  JAXB gerada a partir do schema real usado pela biblioteca **mpxj**
  (referência de fato da comunidade pra ler arquivos MSPDI, validada
  contra arquivos reais do MS Project há anos), que expõe literalmente
  `<xs:element name="Manual" type="xs:boolean" minOccurs="0"/>` dentro de
  `Task`. **Sem confirmação 100% de uma fonte primária Microsoft atual**
  — registrado explicitamente como limitação; se qualquer regra futura
  precisar de garantia absoluta, revisitar com um arquivo real exportado
  por uma versão conhecida do MS Project.
- **`TarefaImportada` ganhou 2 propriedades novas**, ambas com default
  `null` (aditivo, não quebra nenhum ponto de construção existente):
  `agendamentoManual` (`?bool` — true=Manualmente Agendada,
  false=Automaticamente Agendada, `null`=desconhecido) e
  `agendamentoManualBruto` (`?string` — valor exatamente como veio do
  XML, mesmo padrão de preservação já usado em `tipoCodigoOriginal`/
  `tipoRestricaoCodigoOriginal`). **Ausência do elemento `<Manual>`
  NUNCA é tratada como "automática" nem como "manual"** — schemas
  anteriores ao Project 2010 nem tinham esse conceito, então "ausente"
  fica genuinamente desconhecido, nunca um valor assumido.
- **`MsProjectImporter::boolOuNulo()`** (novo helper privado): normaliza
  o valor bruto de `<Manual>` — aceita tanto `'1'`/`'0'` quanto
  `'true'`/`'false'` (o tipo `xs:boolean` do XML Schema permite as duas
  formas léxicas), qualquer outro valor (incluindo ausência) vira `null`
  — nunca inventa um sentido pra um valor inesperado (`<Manual>2</Manual>`
  também vira `agendamentoManual = null`, mas com `agendamentoManualBruto
  = '2'` preservado pra auditoria). **`stringOuNulo()`** (novo, também
  privado): converte string vazia (elemento ausente) em `null`, espelha
  o `intOuNulo()` já existente da Fase 2A.
- **Zero mudança** em HH/baseline/realizado/tendência/predecessoras/
  estrutura/`aplicar()` — a única mudança em `MsProjectImporter.php` foi
  uma linha nova no array `$tarefasRaw[$uid]` + 2 helpers privados + 2
  argumentos novos em `montarTarefa()`.
- **Fixture**: `tests/Fixtures/cronograma_fase2b2a_modo_agendamento.xml`
  (6 tarefas: `Manual=1`, `Manual=0`, ausente, `Manual=true`,
  `Manual=false`, valor inesperado `Manual=2`).
- Testes: `tests/Feature/MsProjectImporterModoAgendamentoTest.php` (7
  testes — mesma convenção de `MsProjectImporterEstruturalTest.php`,
  chamando `analisar()` direto sem Livewire). Suíte completa: **1069
  passed / 6 skipped, 0 failures** (de 1062 antes desta etapa).
- **Não implementado nesta fase** (fica pra Fase 2B.2B, aguardando
  aprovação): nenhuma regra LOGIC-*, nenhuma mudança no
  `HealthCheckEngine`/regras existentes/Blade/Score/migrations.
- **Não avançar pra Fase 2B.2B (LOGIC-005/008/009/010) sem validação do
  usuário** (instrução explícita) — aguardando aprovação desta captura
  antes de implementar qualquer regra.

## Health Check — Fase 2B.2B (LOGIC-005, LOGIC-008, LOGIC-009, LOGIC-010)

- **Contexto**: primeira leva de regras LOGIC aprovadas do diagnóstico da
  Fase 2B.2 — só as 4 de maior confiança (sem depender de datas previstas
  nem do modo de agendamento capturado na Fase 2B.2A). LOGIC-001/002/003/
  004/006/007/011 seguem fora de escopo, aguardando decisão futura.
  Categoria nova: `Logica` (`App\Enums\HealthCheckCategoria::Logica`).
  Total de regras do Health Check: 24 (Fase 1) + 5 (STRUCT, Fase 2B.1) +
  4 (LOGIC, Fase 2B.2B) = **33**.
- **`App\Support\HealthCheck\TarefasPorUid`** (novo helper pequeno,
  compartilhado pelas 4 regras): índice `uid => TarefaImportada` com
  **todas** as tarefas do plano (`criar + atualizar + pacotes`), incluindo
  tarefas-resumo e inativas — deliberadamente diferente de
  `HealthCheckGrafoCronograma` (Fase 2B.1), que exclui as duas. As regras
  LOGIC precisam resolver predecessora/sucessora SEM esse filtro (ex.:
  LOGIC-009 precisa saber quando a predecessora é resumo; LOGIC-010
  precisa enxergar a predecessora mesmo inativa) — por isso é uma
  abstração separada, não uma extensão do grafo estrutural. Também expõe
  `resumo(TarefaImportada): array` (uid/código/nome), serialização mínima
  comum reaproveitada pelos 4 findings.
- **LOGIC-005 (múltiplos vínculos entre o mesmo par)**: trabalha sobre os
  `PredecessoraLink` BRUTOS de cada tarefa (nunca sobre o grafo, que
  colapsa múltiplos vínculos do mesmo par numa única aresta). Agrupa por
  `predecessoraUid` dentro de cada sucessora e classifica cada grupo de
  2+ vínculos em 3 casos, cada um com seu próprio finding (mesmo padrão
  multi-finding de STRUCT-004/005): **Caso A** (duplicidade exata — mesmo
  `tipoCodigoOriginal` + mesmo `linkLag` + mesmo `lagFormat`, severidade
  Médio), **Caso B** (tipos diferentes, severidade Informativo — comum em
  cronogramas migrados do Primavera P6, que permite múltiplos tipos de
  relação entre o mesmo par nativamente), **Caso C** (mesmo tipo, lag
  diferente, severidade Médio). Prioridade de classificação num grupo com
  3+ vínculos mistos: tipos diferentes sempre vence (Caso B), mesmo que
  os lags também divirjam. `LinkLag`/`LagFormat` nunca convertidos nem
  interpretados por magnitude — só comparados por igualdade bruta (mesmo
  princípio "não converter silenciosamente" da Fase 2A).
- **LOGIC-008 (inconsistência entre datas reais em relação FS)**: só
  avalia quando a relação é `TipoRelacionamentoPredecessora::FinishToStart`
  E `ActualFinish` da predecessora (`realTermino`) E `ActualStart` da
  sucessora (`realInicio`) existem — ausência de qualquer uma faz o
  vínculo ser ignorado (nunca tratado como "sem problema" nem como
  "problema"). Dispara só quando `sucessora.realInicio < predecessora.
  realTermino` (comparação estrita — datas iguais não disparam). Ignora
  vínculos em que a predecessora OU a sucessora é tarefa-resumo ou está
  inativa. Severidade Alto (maior confiança de toda a Fase 2B.2 — dado já
  aconteceu, não é recalculado pelo MS Project em nenhum modo de
  agendamento, diferente das futuras LOGIC-001/002/003 que avaliam datas
  PREVISTAS). Nunca usa `dataInicio`/`dataTermino`/baseline/percentual/
  tendência — exclusivamente `realInicio`/`realTermino`, mesmos campos já
  usados pelas regras de Datas da Fase 1.
- **LOGIC-009 (vínculo envolvendo tarefa-resumo)**: um único loop sobre
  todas as tarefas (via `TarefasPorUid`, que inclui resumo) resolve os 2
  lados possíveis ao mesmo tempo — "predecessora é resumo" (a tarefa
  referenciada por `predecessoraUid` tem `isSummary=true`) e "sucessora é
  resumo" (a própria tarefa dona do vínculo, i.e. a que declara
  `predecessoras`, tem `isSummary=true`) — sem duplicar lógica pros dois
  casos. Campo `direcao` no registro do finding distingue
  `predecessora_e_resumo`/`sucessora_e_resumo`/`ambas_resumo`. Severidade
  Informativo — o MS Project não bloqueia vínculo com resumo de nenhum
  dos dois lados, então o texto nunca afirma que é erro. Não altera em
  nada o comportamento do grafo estrutural (Fase 2B.1) nem das regras
  STRUCT-*, que continuam ignorando esses vínculos silenciosamente — esta
  regra é só um diagnóstico complementar que enxerga o que o grafo não
  enxerga.
- **LOGIC-010 (sucessora ativa com predecessora(s) inativa(s))**: só
  considera sucessoras executáveis e ativas (`!isSummary && ativa`) com
  ao menos 1 predecessora capturada; resolve cada predecessora via
  `TarefasPorUid` (que não exclui inativas, ao contrário do grafo) e
  separa em ativas/inativas. **Duas severidades diferentes, decisão
  tomada durante a implementação** (não estava fixada no pedido, registro
  explícito da justificativa): quando **todas** as predecessoras
  capturadas estão inativas → severidade `Baixo` (sinal mais forte, a
  atividade não tem mais nenhuma base lógica ativa remanescente); quando
  há **mistura** de ativas e inativas → severidade `Informativo` (sinal
  mais fraco, a lógica ainda é sustentada pela(s) predecessora(s) ativa(s)
  restante(s)). Em ambos os casos o texto deixa claro que a desativação
  pode ter sido decisão deliberada do planejador — nunca acusa como erro.
- **Risco de falso positivo conhecido, por regra** (documentado
  explicitamente, nenhuma regra afirma "erro"): LOGIC-005 Caso B (tipos
  diferentes) e Caso C (lag diferente) podem ser 100% intencionais;
  LOGIC-008 é a de menor risco (só dados já realizados); LOGIC-009 é
  sempre informativo (MS Project permite os dois lados); LOGIC-010
  explicitamente pode refletir desativação deliberada.
- **UI**: `resources/views/pages/radar/_partials/health-check-findings.blade.php`
  ganhou 4 layouts novos (branches por `regra_id`) — LOGIC-005 (tabela de
  vínculos por par predecessora/sucessora), LOGIC-008 (tabela predecessora
  ×término real / sucessora×início real), LOGIC-009 (tabela com coluna
  "Lado resumo"), LOGIC-010 (lista de predecessoras inativas em vermelho +
  ativas). Resumo por categoria/severidade já funciona sem nenhuma mudança
  de código (mesmo mecanismo genérico documentado na Fase 2B.1) — só
  passou a mostrar "Lógica" porque o enum ganhou o case novo.
- **`HealthCheckEngine::regrasEstruturaisPadrao()`** ganhou as 4 classes
  novas ao final do array já existente — **nenhuma mudança de assinatura,
  nenhum novo parâmetro de construtor**: as regras LOGIC reaproveitam
  exatamente o mesmo `HealthCheckRegraEstruturalInterface`/mesmo array
  `$regrasEstruturais` das regras STRUCT (Fase 2B.1), já que a interface
  já suportava múltiplos findings por regra desde o desenho original.
  Verificado que isso não contamina nenhum teste pré-existente: os
  fixtures de `HealthCheckEngineTest.php` (Fase 1) nunca populam
  `predecessoras`; os de `HealthCheckEstruturalTest.php` (Fase 2B.1) nunca
  criam vínculos duplicados, tarefas-resumo linkadas ou predecessoras
  inativas referenciadas por uma sucessora ativa; e as asserções desses
  arquivos sempre buscam o finding por `regraId` específico (nunca a
  lista completa), tolerando naturalmente a presença de findings de
  outras regras.
- Testes: `tests/Unit/HealthCheckLogicaTest.php` (26 testes — as 4 regras
  isoladas via `regras: []`, cobrindo todos os cenários pedidos: os 3
  casos de LOGIC-005 + pares diferentes/vínculo único não disparam; os 10
  cenários de LOGIC-008 incluindo os 4 tipos de relacionamento e resumo/
  inativa; os 5 cenários de LOGIC-009; os 6 cenários de LOGIC-010),
  `tests/Feature/HealthCheckLogicaIntegrationTest.php` (6 testes — fluxo
  completo XML → `MsProjectImporter::analisar()` → `HealthCheckEngine::
  avaliar()` com o motor PADRÃO, fixture nova `tests/Fixtures/
  cronograma_fase2b2b_logica.xml` cobrindo as 4 regras + confirmando que
  as regras STRUCT-* da Fase 2B.1 continuam funcionando lado a lado). 32
  testes novos no total. Suíte completa: **1101 passed / 6 skipped, 0
  failures** (de 1069 antes desta fase), incluindo `TenantIsolationTest` e
  toda a suíte de Fase 1/2A/2B.1/2B.2A sem nenhuma regressão.
- **Não tocado**: `MsProjectImporter.php` (Fase 2A/2B.2A já capturaram
  tudo que esta fase precisa — nenhuma linha alterada), Score
  (`scorePreliminar()` continua `null`), as 24 regras da Fase 1, as 5
  regras STRUCT da Fase 2B.1, migrations, HH/baseline/realizado/
  tendência/reconciliação/`aplicar()`, polling, fluxo de abortar.
- **Não avançar pra nenhuma regra LOGIC adicional (001/002/003/004/006/
  007/011), regras de folga (SLACK-*), ou qualquer outra fase sem
  validação do usuário** (instrução explícita) — aguardando aprovação
  desta etapa antes de continuar.

## Health Check — Fase 2B.3 (SLACK-001, SLACK-002, SLACK-005)

- **Contexto**: última leva aprovada do diagnóstico de folga — só as 3
  regras que não dependem de `Critical`/`CriticalSlackLimit`.
  SLACK-003/004/007/008 ficam bloqueadas até `CriticalSlackLimit` (campo
  de nível `<Project>`, não `<Task>` — nunca capturado) ser lido e sua
  unidade validada; SLACK-006 (folga elevada) foi descartada por exigir
  um threshold arbitrário sem critério técnico objetivo. Categoria nova:
  `Slack` (label "Folgas"). Total de regras do Health Check: 24 (Fase 1)
  + 5 (STRUCT) + 4 (LOGIC) + 3 (SLACK) = **36**.
- **Achado do diagnóstico que definiu a arquitetura**: `TotalSlack`/
  `FreeSlack` são medidos em "décimos de minuto" (fonte: documentação da
  API do mpxj, com fórmula de exemplo — confiança maior que a que
  tínhamos pra `LagFormat`), mas SEM nenhum campo "Format" companheiro —
  diferente de `LinkLag`/`LagFormat`, a unidade aqui é fixa, não
  configurável por vínculo. Ainda assim, **nenhuma conversão foi feita**
  nesta fase (decisão do usuário) — as 3 regras usam só comparação de
  sinal/relação (`< 0`, `> `), nunca magnitude convertida.
- **`App\Support\HealthCheck\RegraHealthCheckSlackPorAtividadeBase`**
  (novo, espelha `RegraHealthCheckEstruturalPorAtividadeBase` mas SEM
  depender de `HealthCheckGrafoCronograma`) — folga é um valor já
  calculado pelo MS Project por atividade, não uma propriedade da rede
  que reconstruímos via `PredecessorLink`, então não faz sentido
  reaproveitar o grafo nem sua guarda de "sem nenhuma relação capturada".
  Itera `$plano->criar + $plano->atualizar` (nunca `pacotes`), filtra
  `isSummary`/`!ativa` explicitamente (redundante com o fato de resumo
  nunca estar em criar/atualizar, mas mantido por clareza/robustez), e
  cada regra concreta só implementa `combina(TarefaImportada): bool`.
- **SLACK-001 (TotalSlack negativo)**: severidade Alto. **SLACK-002
  (FreeSlack negativo)**: severidade Médio. **SLACK-005 (FreeSlack >
  TotalSlack)**: severidade Médio, comparação puramente matemática
  (testado explicitamente com os dois valores negativos, ex.: `-50 >
  -100` dispara, `-100 > -50` não dispara — não é baseado em sinal).
  Nenhuma das 3 usa `Critical` em nenhum momento (fora de escopo
  explícito desta fase). `null` nunca vira zero — ausência de
  `totalSlack`/`freeSlack` faz a regra simplesmente não avaliar aquele
  campo pra aquela tarefa (`combina()` retorna `false`).
- **Tratamento de tipos especiais** (igual pras 3 regras): tarefa-resumo
  SEMPRE ignorada (achado do diagnóstico: um artigo técnico independente
  confirma que `TotalSlack` de resumo é calculado a partir de datas
  adiantada/tardia herdadas de até 4 subtarefas possivelmente
  desconectadas entre si, "sem significado lógico" — recomendação do
  próprio autor é ignorar esse valor); tarefa inativa SEMPRE ignorada
  (valor congelado, mesmo padrão de STRUCT-*/LOGIC-*); **marco NÃO é
  ignorado** (participa do CPM normalmente, sem quirk documentado);
  atividade sem predecessora/sucessora capturada **NÃO é excluída por
  esse motivo** — SLACK analisa o resultado que o MS Project já calculou
  internamente, diferente de STRUCT (que analisa a rede que
  reconstruímos via `PredecessorLink`).
- **Achado durante os testes de integração, não um bug**: `SLACK-001` e
  `SLACK-002` co-ocorrem com frequência em dados realistas — como
  `FreeSlack <= TotalSlack` é uma relação matemática garantida por
  definição de CPM, `TotalSlack < 0` quase sempre força `FreeSlack < 0`
  também (a exceção teórica exigiria um `FreeSlack` positivo com
  `TotalSlack` negativo, o que violaria a própria relação e seria pego
  por SLACK-005). A fixture `cronograma_fase2b3_slack.xml` e o teste de
  integração documentam isso explicitamente (UID 10 e o marco UID 15
  disparam as duas regras juntas; UID 11 é o caso isolado — TotalSlack
  positivo, só FreeSlack negativo).
- **UI**: `health-check-findings.blade.php` ganhou 1 layout novo (tabela
  código/atividade/UID/TotalSlack bruto/FreeSlack bruto, com nota
  explícita "valores brutos do XML, sem conversão"). Resumo por
  categoria/severidade já funciona sem nenhuma mudança de código — só
  passou a mostrar "Folgas" porque o enum ganhou o case novo.
- **`HealthCheckEngine::regrasEstruturaisPadrao()`** ganhou as 3 classes
  novas ao final do array — nenhuma mudança de assinatura do construtor.
  Verificado que isso não contamina nenhum teste pré-existente: nenhuma
  fixture de Fase 1/2A/2B.1/2B.2B popula `totalSlack`/`freeSlack` com
  valores que disparariam as novas regras (a maioria nem popula o campo).
- Testes: `tests/Unit/HealthCheckSlackTest.php` (23 testes — as 3 regras
  isoladas via `regras: []`, cobrindo os 7 cenários de cada uma:
  negativo/zero/positivo/nulo/resumo/inativa/marco para 001 e 002, e os
  7 equivalentes + 2 comparações matemáticas puras com valores negativos
  pra 005), `tests/Feature/HealthCheckSlackIntegrationTest.php` (5
  testes — fluxo completo XML → `MsProjectImporter::analisar()` →
  `HealthCheckEngine::avaliar()` com o motor PADRÃO, fixture nova
  `tests/Fixtures/cronograma_fase2b3_slack.xml` com os 8 cenários
  pedidos). 28 testes novos no total. Suíte completa: **1129 passed / 6
  skipped, 0 failures** (de 1101 antes desta fase), incluindo
  `TenantIsolationTest` e toda a suíte de Fase 1/2A/2B.1/2B.2A/2B.2B sem
  nenhuma regressão.
- **Achados de implementação, não do código de produção**: (1) um
  comentário PHPDoc continha literalmente `STRUCT-*/LOGIC-*`, cuja
  substring `*/` fechou o comentário prematuramente e quebrou o parser
  PHP — corrigido trocando por vírgula; (2) a primeira versão da fixture
  de integração dava `FreeSlack` negativo também às atividades pensadas
  pra isolar só SLACK-001, o que fez SLACK-002 dispará-las também — não
  é bug (é a matemática de CPM descrita acima), só corrigida a
  expectativa do teste, não o código.
- **Não implementado nesta fase, aguardando decisão futura**:
  `CriticalSlackLimit` (campo de `<Project>`, não capturado), SLACK-003/
  004/006/007/008, qualquer comparação envolvendo `Critical`, conversão
  de unidade de `TotalSlack`/`FreeSlack`.
- **Não tocado**: `MsProjectImporter.php` (Fase 2A já capturou
  `totalSlack`/`freeSlack` — nenhuma linha alterada nesta fase), Score,
  as 24 regras da Fase 1, as 5 regras STRUCT, as 4 regras LOGIC,
  migrations, HH/baseline/realizado/tendência/reconciliação/`aplicar()`,
  polling, fluxo de rollback/abortar.

## Health Check — 3 ajustes de UX pós-Fase 2 (achados em teste real do usuário)

- **Contexto**: depois de um teste real e completo de tudo que foi
  construído na Fase 2 (2A a 2B.3), o usuário identificou 3 problemas de
  UX/comportamento de interface — **nenhuma regra de Health Check,
  severidade, código STRUCT/LOGIC/SLACK, DTO, parser, Engine ou Score foi
  tocado nesta etapa**, só Blade/Alpine das duas telas de importação e do
  partial compartilhado de findings.
- **1) Corrida entre `x-on:change` e o upload do Livewire**: o botão
  "Analisar" (`⚡cronograma.blade.php`) ficava clicável um instante antes
  de o Livewire terminar de processar o upload, porque `hasFile` era
  setado dentro do `x-on:change` bruto do `<input type="file">` — esse
  evento nativo `change` é o MESMO evento que o listener interno do
  `wire:model` do Livewire escuta pra iniciar o upload, sem nenhuma ordem
  de execução garantida entre os dois listeners. Corrigido movendo TODA
  transição de `hasFile` pros eventos que o próprio Livewire dispara,
  nessa ordem garantida: `livewire-upload-start` (`hasFile = false`),
  `livewire-upload-finish` (`hasFile = true`), `livewire-upload-error`
  (`hasFile = false`) — o `x-on:change` do input agora só lê o tamanho do
  arquivo pra mensagem de limite de plano (`tamanhoSelecionadoMb`), nunca
  mais mexe em `hasFile`. `⚡relatorio-importar-avanco.blade.php` **não
  tinha NENHUM controle de estado do botão** antes deste ajuste (upload
  "cru", sem feedback) — ganhou o mesmo mecanismo do zero (sem a parte de
  limite de upload por plano, que não existe nessa tela).
- **2) Feedback "discreto demais" durante a análise**: como
  `HealthCheckEngine::avaliar()` roda inteiro DENTRO da mesma requisição
  síncrona de `analisar()` (não há Job/fila nessa etapa — só a
  persistência final em `confirmar()`/`ImportarCronogramaJob` é
  assíncrona), não existe um checkpoint real de progresso pra reportar
  percentual de conclusão com honestidade. Em vez de inventar uma barra
  de progresso fake (como a versão anterior fazia, com um `%` estimado
  por tempo), o bloco `wire:loading wire:target="analisar"` das duas
  telas passou a mostrar uma **checklist de etapas reais do pipeline**
  (Recebendo arquivo → identificando EAP → interpretando atividades →
  montando predecessoras/sucessoras → estrutura da rede → lógica →
  folgas → Health Check → consolidando prévia), com um Alpine `setInterval`
  só pra avançar QUAL etapa está em destaque (✓ concluída / ⏳ atual /
  ○ pendente) — nunca um número de "X% concluído". Nomes das etapas
  batem com a ordem real de `HealthCheckEngine::regrasEstruturaisPadrao()`
  (STRUCT → LOGIC → SLACK) seguida pelas 24 regras da Fase 1. O
  formulário inteiro (incluindo o botão) fica atrás de
  `wire:loading.remove wire:target="analisar"` — a MESMA técnica já
  usada no projeto pra impedir dupla submissão (o botão literalmente some
  do DOM durante a requisição, não só fica desabilitado).
- **3) Accordion de findings não fechava no segundo clique**:
  `data-bs-toggle="collapse"` nativo do Bootstrap mantém seu PRÓPRIO
  estado em JS (classe `.show`/`.collapse` no elemento), que pode
  dessincronizar do DOM real depois que o Livewire faz um morph na
  árvore — resultado observado: `fechado → expandido → expandido` (nunca
  voltava a fechar). Confirmado via grep que `@alpinejs/collapse` NÃO
  está instalado no projeto — corrigido substituindo o mecanismo inteiro
  por um toggle explícito do Alpine core (`x-data="{ aberto: false }"` no
  card, `@click="aberto = !aberto"` no cabeçalho, `x-show="aberto"
  x-transition x-cloak` no conteúdo, sem nenhuma dependência nova) em
  `health-check-findings.blade.php` — mesmo padrão já documentado no
  projeto de `wire:key` estável por linha (`wire:key="{{ $idPrefix }}-{{
  $loop->index }}"`) pra Alpine nunca perder seu escopo entre renders do
  Livewire. Nenhum dos layouts internos por tipo de regra (STRUCT/LOGIC/
  SLACK/Fase 1) foi alterado — só o mecanismo de abrir/fechar o
  cabeçalho.
- **Achado nos próprios testes novos (não é bug de produção)**: o
  primeiro teste do accordion pro caminho de Avanço
  (`relatorio-importar-avanco`) falhou porque a importação de Avanço só
  ATUALIZA atividades já casadas por `external_uid` (nunca cria) — sem
  nenhuma atividade pré-existente na obra de teste, o XML inteiro virava
  "ignorado" e o Health Check não tinha nada pra avaliar (0 findings),
  mesmo usando uma fixture que dispara alertas garantidos pelo caminho de
  Baseline. Corrigido no teste (não no código): importa a MESMA fixture
  como Baseline primeiro (mesmo setup já usado em
  `CronogramaHealthCheckTest::test_importacao_de_avanco_calcula_e_persiste_health_check`),
  só depois testa o Avanço sobre as atividades já casadas.
- **Não tocado**: `MsProjectImporter.php`, `HealthCheckEngine`, todas as
  36 regras (24 Fase 1 + 5 STRUCT + 4 LOGIC + 3 SLACK), DTOs, Score,
  `ImportarCronogramaJob`, o polling de status já existente (reaproveitado
  como estava, não duplicado), qualquer lógica de negócio.
- Testes: `tests/Feature/ImportacaoUxAjustesTest.php` (13 testes) —
  estado do botão nas duas telas, checklist de etapas reflete o pipeline
  real, formulário atrás de `wire:loading.remove`, accordion usa toggle
  do Alpine (nunca mais `data-bs-toggle="collapse"`) nas duas telas, e um
  teste de regressão confirmando que analisar/confirmar continuam
  funcionando normalmente. Suíte completa sem regressão, incluindo
  `TenantIsolationTest` e toda a suíte de Fase 1/2A/2B.1/2B.2A/2B.2B/2B.3.

## Health Check — Fase 3, Etapa 3 (motor de cálculo do Score de Saúde)

- **Contexto**: primeira etapa da Fase 3 (Score de Saúde + Histórico de
  Importações) — implementa SOMENTE o motor matemático do Score
  (`ScoreCalculator`) e seus testes unitários. **Nenhuma persistência,
  migration, UI, Blade, Livewire ou histórico foi tocado nesta etapa** —
  isso fica para as etapas 4-11, aguardando validação desta primeira.
  Score era, até aqui, `HealthCheckResultado::scorePreliminar()` retornando
  `null` sempre (decisão deliberada da Fase 1 de não inventar uma fórmula
  sem validação) — essa etapa implementa a fórmula validada pelo usuário,
  num serviço separado, sem tocar `scorePreliminar()` nem nenhuma regra das
  Fases 1/2A/2B.1/2B.2A/2B.2B/2B.3.
- **`app/Support/HealthCheck/Score/`** (subpasta nova, mesmo espírito de
  `Rules/Estrutura|Logic|Slack/` — isola o domínio novo sem poluir o nível
  raiz de `HealthCheck/`): `FaixaScore` (enum), `ScoreDimensao`/
  `AcaoRecomendada`/`ScoreResultado` (DTOs `readonly`, mesmo padrão de
  `HealthCheckFinding`/`HealthCheckResultado`), `ScoreCalculator` (o
  serviço, `final class`, sem estado). `ScoreCalculator::calcular
  (HealthCheckResultado $resultado, PlanoImportacao $plano): ScoreResultado`
  — consome o resultado JÁ produzido pelo `HealthCheckEngine`, nunca
  reimplementa nenhuma regra.
- **4 camadas explícitas** (nunca uma fórmula obscura, por pedido
  explícito do usuário): (1) Severidade → peso bruto
  (`HealthCheckSeveridade::peso()`, já existente, reaproveitado sem
  alteração); (2) peso bruto → peso máximo (`peso_bruto × FATOR_ESCALA`,
  `FATOR_ESCALA = 10` como constante nomeada em `ScoreCalculator` — nunca
  um número mágico espalhado); (3) peso máximo → impacto do finding
  (`peso_maximo × (quantidade_atividades_do_finding ÷
  total_atividades_elegiveis)` — quantidade vem de
  `HealthCheckFinding::quantidade()`, já existente, nunca uma nova forma de
  contar); (4) soma de todos os impactos → Score
  (`clamp(100 + soma, 0, 100)`, arredondado **só no resultado final**,
  nunca finding a finding antes da soma — evita erro de arredondamento
  acumulado).
- **Score por dimensão** deriva de `HealthCheckCategoria::cases()`
  dinamicamente (nunca uma lista fixa de 7/10 categorias) — mesma fórmula
  de 4 camadas, só somando os impactos dos findings daquela categoria;
  categoria sem finding fica em 100. Uma categoria nova implementada no
  futuro entra automaticamente no Score, sem tocar `ScoreCalculator`.
- **"Atividades elegíveis" = mesmo universo já usado por STRUCT/SLACK**
  (`!isSummary && ativa`, contado direto de `$plano->criar + $plano->
  atualizar`) — não é um conceito novo, é literalmente o mesmo filtro de
  `HealthCheckGrafoCronograma`/`RegraHealthCheckSlackPorAtividadeBase`,
  reaproveitado (não duplicado em espírito, mas replicado em código já que
  `ScoreCalculator` não depende do grafo nem da base de regras — decisão
  deliberada de manter o motor de Score desacoplado dessas classes).
- **Cobertura** = atividades executáveis E ativas ÷ atividades executáveis
  (ativas ou não) × 100. **Achado/decisão desta etapa**: o pedido original
  associava "cobertura = null" ao caso "total de atividades elegíveis =
  0" — mas isso colide com o caso em que existem atividades executáveis,
  porém TODAS inativas (elegíveis = 0, mas executáveis > 0). Resolvido
  assim: `null` só no caso de divisão por zero de verdade (nenhuma
  atividade executável no plano — nada pra medir); quando existem
  executáveis mas todas inativas, cobertura = `0` (não `null`) — decisão
  deliberada, porque `0%` é o sinal de baixa confiança mais forte que
  existe e é exatamente isso que deve ser sinalizado ao usuário, nunca
  silenciado como "sem dado". Tarefas-resumo nunca entram em nenhum dos
  dois lados da conta (filtro `!isSummary`, defensivo — resumo nunca
  aparece em `criar`/`atualizar` de qualquer forma).
- **Caso sem atividades elegíveis** (`total_atividades_elegiveis === 0`):
  Score geral = 100, todas as dimensões = 100, Mapa de Ações vazio,
  potencial recuperável = 0, cobertura conforme a regra acima — sem
  nenhuma divisão por zero.
- **`FaixaScore`** (enum): 90-100 Excelente · 80-89 Bom · 70-79 Atenção ·
  60-69 Necessita atenção · 0-59 Crítico — única fonte de verdade dos
  thresholds, resolvida via `FaixaScore::paraScore(int $score): self`.
- **Mapa de Ações**: filtra findings com `severidade->peso() < 0`
  (Informativo nunca entra — não reduz Score, não é "ação penalizadora"),
  ordena por severidade mais grave → impacto absoluto maior → quantidade
  de atividades maior → `regra_id` ascendente (desempate estável).
  **Achado de arquitetura**: como todos os findings de uma mesma chamada
  compartilham o mesmo `total_atividades_elegiveis`, "mesma severidade +
  mesmo impacto" implica matematicamente "mesma quantidade" (impacto é
  proporcional à quantidade para severidade/total fixos) — o critério de
  desempate por quantidade nunca decide de forma diferente do critério por
  impacto dentro desta fórmula específica. Os 4 critérios foram
  implementados exatamente como especificado (defesa barata caso a fórmula
  mude no futuro), documentado no teste correspondente. `recomendacao` de
  cada `AcaoRecomendada` é sempre copiada verbatim de
  `HealthCheckFinding::$recomendacao` — nenhum texto novo inventado.
  `potencialRecuperavel = 100 - score` (quantos pontos, no máximo, seriam
  recuperados corrigindo tudo — bounded em [0,100], ao contrário da soma
  bruta dos impactos, que pode superar 100 quando o Score já bateu no
  piso 0).
- **Decisão de calibração explícita, sem piso mínimo para ciclos
  (STRUCT-005)**: tratado exatamente como qualquer outra regra, 100%
  proporcional à quantidade de atividades afetadas — um ciclo lógico de 3
  atividades num cronograma de 2.000 fica quase invisível no Score geral
  (impacto ≈ -0,15, arredonda para Score 100), mesmo sendo um problema
  estrutural grave em termos absolutos (CPM não fecha com ciclo). Decisão
  deliberada do usuário, documentada explicitamente no teste
  `test_mesmo_finding_cronograma_grande_sem_piso_minimo_para_ciclos` — se
  o comportamento não for satisfatório na prática, é um ajuste de
  calibração futuro (ex.: piso mínimo por regra), não implementado agora.
- **Nada alterado**: `MsProjectImporter`, `HealthCheckEngine`, as 36 regras
  (24 Fase 1 + 5 STRUCT + 4 LOGIC + 3 SLACK), `HealthCheckResultado::
  scorePreliminar()` (continua retornando `null` — o Score novo vive
  inteiramente em `ScoreCalculator`, fora do resultado do Engine), DTOs
  existentes, migrations, HH/baseline/realizado/tendência/reconciliação/
  `aplicar()`, polling, os 3 ajustes de UX da etapa anterior.
- Testes: `tests/Unit/ScoreCalculatorTest.php` (31 testes — score básico
  por severidade, proporcionalidade incluindo os Exemplos A/B/C exatos
  aprovados pelo usuário, quantidade de atividades, dimensões dinâmicas,
  limites 0/100, cobertura nos 5 cenários, mapa de ações com os 4
  critérios de desempate, explicabilidade) + `tests/Unit/FaixaScoreTest.php`
  (10 testes — os 10 limites exatos pedidos: 100/90/89/80/79/70/69/60/59/0).
  41 testes novos no total, todos passando isolados na primeira rodada.
  Suíte completa sem regressão (ver resultado da rodada no relatório de
  conclusão desta etapa).
- **Não avançar para persistência do Score, migration, Histórico de
  Importações, tela de detalhe ou UI sem validação do usuário** (instrução
  explícita) — aguardando aprovação desta etapa antes de continuar.

## Health Check — Fase 3, Etapa 4 (persistência do Score)

- **Contexto**: segunda etapa da Fase 3 — persiste o `ScoreResultado` (já
  validado na Etapa 3) como parte do snapshot de
  `CronogramaImportacaoHealthCheck`, 1:1 com `CronogramaImportacao`. Nenhum
  Histórico de Importações, tela de detalhe ou UI do Score foi tocado —
  fica pras próximas etapas.
- **Migration nova** (nunca editar a de 28/07 já aplicada):
  `2026_07_31_000001_add_score_to_cronograma_importacao_health_checks_table.php`
  — 7 colunas, **todas nullable, sem default**: `score`/`cobertura`/
  `potencial_recuperavel` (`unsignedTinyInteger`, cabem em 0-100),
  `faixa_score`/`versao_score` (`string`), `score_por_dimensao`/
  `mapa_acoes` (`json`). Registros de `CronogramaImportacaoHealthCheck`
  criados ANTES desta etapa ficam com as 7 em `NULL` — **sem backfill,
  sem Score retroativo inventado**, decisão explícita do usuário.
- **Ponto de integração — achado que evitou tocar Blade/Livewire**: em vez
  de calcular o Score na prévia (`analisar()` dos componentes Livewire, que
  já teve 3 ajustes de UX recém-aprovados — risco desnecessário de mexer
  ali), o Score é calculado dentro do `DB::transaction()` já existente em
  `ImportarCronogramaJob::handle()`, **reaproveitando o `$plano`
  (`PlanoImportacao`) que o próprio Job já produz** ao chamar
  `$importer->analisar()` antes de `aplicar()` — nenhum parse novo,
  nenhuma fila nova, nenhuma segunda análise. O `HealthCheckResultado` é
  reidratado de volta a partir do MESMO `$this->healthCheckSerializado`
  que a prévia já mostrou ao usuário (`HealthCheckResultado::fromArray()`,
  já existente) — o Score nasce do que o usuário viu, nunca de um
  recálculo divergente. `ScoreCalculator` (Etapa 3) permanece a ÚNICA
  fonte de verdade do cálculo — zero fórmula duplicada.
- **Atomicidade de graça**: Health Check e Score são gravados no MESMO
  `CronogramaImportacaoHealthCheck::create()` (um único array combinado de
  `camposParaPersistir()` + `camposDeScoreParaPersistir()`, o novo método
  irmão) — não existe estado intermediário possível "Health Check gravado,
  Score não". Se `ScoreCalculator::calcular()` ou o `create()` falhar,
  toda a transação (incluindo `aplicar()`) reverte, como já acontecia
  antes desta etapa — nenhuma mudança no mecanismo de rollback.
- **`ScoreDimensao`/`AcaoRecomendada` ganharam `toArray()`/`fromArray()`**
  (mesmo padrão de `HealthCheckFinding`) — só essas duas; `ScoreResultado`
  em si NÃO precisou de round-trip próprio, porque `score_por_dimensao` e
  `mapa_acoes` são colunas JSON SEPARADAS (não um blob único), então o
  model monta/desmonta cada uma independentemente.
  `CronogramaImportacaoHealthCheck::scoreResultado(): ?ScoreResultado`
  (irmã de `resultado(): HealthCheckResultado`, já existente) reidrata o
  snapshot completo a partir das 7 colunas — retorna `null` sem lançar
  exceção pra registros antigos (`score === null`).
  `versao_score` vem de `ScoreResultado::$versaoFormula`, que por sua vez
  já veio de `ScoreCalculator::VERSAO_FORMULA` — nunca duplicada em
  lugar nenhum.
- **Achado de teste, não de produto**: `App\Models\Concerns\BelongsToTenant`
  carimba `tenant_id` a partir de `TenantContext::currentId()` no evento
  `creating`, **ignorando qualquer valor explícito** passado em
  `Model::create()` (mesma trava de segurança já documentada no início
  deste arquivo: "nunca definir tenant_id à mão em código de request").
  O teste de isolamento desta etapa precisou criar o registro do "outro
  tenant" dentro de `TenantContext::actingAs($outroTenant, ...)` — passar
  `'tenant_id' => $outroTenant->id` direto no array de `create()` é
  silenciosamente sobrescrito pelo tenant do usuário autenticado no teste,
  o que inicialmente mascarou o teste (o registro "de outro tenant" virava,
  na prática, um registro do PRÓPRIO tenant do teste).
- **Não tocado**: `MsProjectImporter`, `HealthCheckEngine`, as 36 regras,
  `ScoreCalculator` (matemática intocada — só passou a ser chamada de um
  lugar novo), `HealthCheckResultado::scorePreliminar()` (continua
  `null`), a migration de 28/07 já aplicada, `camposParaPersistir()`
  (Health Check) existente, os 3 ajustes de UX aprovados, nenhum Blade/
  Livewire, polling, baseline/realizado/tendência/HH/reconciliação.
- Testes: `tests/Feature/CronogramaImportacaoScoreTest.php` (14 testes —
  persistência básica dos 7 campos, Score bate com cálculo independente do
  `ScoreCalculator`, snapshot/imutabilidade, JSON round-trip via DTOs,
  registro antigo carrega com tudo `null` sem exceção e sem backfill,
  rollback preserva a garantia "nunca Health Check sem Score", isolamento
  de tenant). Suíte completa sem regressão, incluindo `TenantIsolationTest`
  e toda a suíte de Fase 1/2A/2B.1/2B.2A/2B.2B/2B.3/Score-Etapa-3/UX-fixes.
- **Não avançar para Histórico de Importações, tela de detalhe ou UI do
  Score sem validação do usuário** (instrução explícita) — aguardando
  aprovação desta etapa antes de continuar.

## Health Check — Fase 3, Etapa 5 (Histórico de Importações + Detalhe do Score)

- **Contexto**: última etapa da Fase 3 — expõe o Score/Health Check
  persistidos (Etapa 4) numa experiência de consulta: histórico
  consolidado + página de detalhe por importação, tratada como fotografia
  auditável. **Nenhuma regra, `ScoreCalculator`, `HealthCheckEngine` ou
  `MsProjectImporter` foi tocado** — só leitura do que já está persistido.
- **Achado de diagnóstico que mudou o plano original**: `⚡cronograma.blade.php`
  já tinha uma seção "Histórico de Importações de Linha de Base" própria
  (computed `historicoImportacoes()` com `withSum`/`withMin`/`withMax`
  pra HH/datas de baseline, tabela com avatar do autor) — não detectada na
  investigação inicial (focada em rotas/Policies, não no conteúdo interno
  das duas páginas de importação). Por decisão explícita do usuário
  ("não quero duas implementações diferentes do histórico"), essa
  implementação foi **substituída** pelo partial novo — colunas
  específicas de Baseline (datas de início/término, Total HH, foto do
  avatar) saíram da lista em troca de uma experiência única e consistente
  nas 3 telas. Documentado aqui como decisão consciente, não perda
  acidental.
- **`app/Policies/CronogramaImportacaoPolicy.php`** (novo, registrado em
  `AuthServiceProvider`): só o método `view`, mesmo padrão de
  `ReportPolicy` — `$user->temPermissaoNaObra($importacao->obra_id,
  'obras.importar_cronograma', 'ver')`. `ver` é liberado por padrão pra
  qualquer perfil com vínculo na obra (`Perfil::REGRAS_ESCRITA` nunca lista
  `ver` — comentário no próprio código confirma "ninguém é bloqueado de
  visualizar hoje"). Isolamento de tenant vem de graça do global scope de
  `BelongsToTenant`; isolamento de obra é o que a Policy garante.
- **Rota nova `radar.importacoes.show`** (`/radar/importacoes/{importacao}`),
  dentro do grupo `obra.context` já existente, mesmo padrão exato de
  `radar.relatorios.show` (closure com route-model-binding +
  `abort_unless(auth()->user()->can('view', $importacao), 403)`).
- **Achado de teste — `AuthorizationException` em `mount()` não propaga
  como exceção crua pro teste**: `App\Exceptions\Handler::render()`
  (Fase de "popup de acesso negado") intercepta qualquer 403 de navegação
  de página cheia e converte num redirect com `flash.popup =
  'acesso-negado'` — mesmo comportamento já usado por
  `⚡relatorio-detalhe.blade.php` (confirmado em
  `ReportDetalheTest::test_encarregado_nao_acessa_detalhe_de_rascunho`).
  Os testes de segurança desta etapa usam `assertRedirect()` +
  `assertSessionHas('flash.popup', 'acesso-negado')`, não
  `expectException()` — descoberto só depois de um teste inicial
  (`expectException(AuthorizationException::class)`) passar silenciosamente
  sem detectar nada, isolado via `tinker` confirmando que a Policy em si
  nega corretamente (`$user->can('view', ...)` já retornava `false`) — o
  problema era só a forma errada de testar, nunca um bug de autorização.
- **`⚡importacao-detalhe.blade.php`**: `mount(CronogramaImportacao
  $importacao)` com `$this->authorize('view', $importacao)` +
  eager-load `['obra', 'autor', 'healthCheck']`. Nunca chama
  `ScoreCalculator`/`HealthCheckEngine` — só lê
  `$importacao->healthCheck->scoreResultado()`/`resultado()` (Etapa 4).
  3 estados tratados sem exceção: sem `healthCheck` nenhum (importação
  anterior à Fase 1) → mensagem amigável, seção de Score/dimensões/mapa
  de ações inteira omitida; `healthCheck` existe mas `scoreResultado()`
  é `null` (importação anterior à Fase 3) → "Score não disponível",
  mesma omissão; ambos presentes → experiência completa. Score por
  dimensão itera `HealthCheckCategoria::cases()` dinamicamente (nunca uma
  lista fixa) — categoria nova aparece sozinha, sem tocar o Blade.
  Findings completos reaproveitam `health-check-findings.blade.php`
  **sem nenhuma alteração**. Faixa por dimensão resolvida via
  `FaixaScore::paraScore($dimensao->score)` — classificação de um número
  já persistido, não recálculo da fórmula (a única matemática nova na
  Blade é `abs()`/`number_format()` de exibição do impacto já calculado).
- **`_partials/score-explicacao.blade.php`** (novo): texto didático
  estático, fonte única, toggle via Alpine puro (`x-data`/`@click`),
  nunca `data-bs-toggle="collapse"` — mesmo motivo já documentado nos
  ajustes de UX pós-Fase 2 (Bootstrap desincroniza dentro de conteúdo
  remontado pelo Livewire).
- **`_partials/historico-importacoes.blade.php`** (novo, único, usado nas
  3 telas — `⚡cronograma.blade.php`, `⚡relatorio-importar-avanco.blade.php`,
  `⚡obra-detalhe.blade.php`): recebe `$importacoes` (paginado,
  `WithPagination` + `paginationTheme = 'bootstrap'`, mesmo padrão já
  usado em `notificacoes/⚡index.blade.php`). Cada página filtra por
  `tipo` no seu próprio computed (`Baseline+Ambos` em `⚡cronograma`,
  `Avanco+Ambos` em `⚡relatorio-importar-avanco`, sem filtro em
  `⚡obra-detalhe`), mas a apresentação é 100% a mesma. Linha clicável via
  `onclick="window.location='...'"` + `style="cursor:pointer"` (sem
  precedente de `wire:navigate` em linha de tabela no projeto — escolhida
  a forma mais simples e robusta). Coluna "Situação" com 3 estados
  (`Sem análise registrada` / `Análise disponível — Score indisponível`
  / `Analisado`), nunca inventando Score pra importação antiga.
- **Não tocado**: `MsProjectImporter`, `HealthCheckEngine`, as 36 regras,
  `ScoreCalculator`, `HealthCheckResultado`, `health-check-findings.blade.php`
  (só incluído, zero linha alterada), fluxo de importação/rollback/polling,
  os 3 ajustes de UX aprovados, migrations existentes (nenhuma migration
  nova nesta etapa — tudo já estava persistido desde a Etapa 4).
- Testes: `tests/Feature/ImportacaoDetalheTest.php` (12 testes — Score/
  faixa/cobertura/potencial recuperável/cabeçalho/dimensões dinâmicas/
  mapa de ações/findings/totais por severidade, 3 cenários de
  compatibilidade sem exceção, dinamismo do enum, 2 cenários de
  segurança) + `tests/Feature/HistoricoImportacoesTest.php` (8 testes —
  aparece nas 3 telas, Score/faixa/cobertura no histórico, situação nos 3
  estados, link de navegação correto, paginação). 20 testes novos no
  total, todos passando isolados na primeira rodada real (após corrigir
  2 falsos-negativos de teste: nome de arquivo persistido não é o nome
  original do upload, e a forma correta de testar negação de acesso via
  redirect, não exceção).

## Health Check — Fase 3.1 (Evolução do Score e Histórico)

- **Contexto**: melhoria de UX em cima do que já está persistido (Etapa 4)
  — nenhuma regra, `ScoreCalculator`, `HealthCheckEngine`, `MsProjectImporter`,
  migration existente ou fluxo de importação/rollback/polling foi tocado.
  Tudo aqui é leitura de dado já gravado; nada é recalculado.
- **`CronogramaImportacao::importacaoAnterior(): ?self`** (novo): resolve a
  importação imediatamente anterior da MESMA obra e do MESMO `tipo`
  (comparação exata — Baseline com Baseline, Avanço com Avanço, Ambos com
  Ambos, nunca misturado) via `where('importado_em', '<', ...)->orderByDesc(...)
  ->first()`, com `healthCheck` eager-loaded (1 query). "Mesmo tipo" é
  decisão deliberada: comparar Scores calculados sobre universos de
  findings potencialmente diferentes (ex.: Avanço nunca dispara regras
  estruturais/estáticas que dependem de dados só presentes numa importação
  completa) produziria uma comparação sem sentido.
- **`⚡importacao-detalhe.blade.php`** ganhou 3 blocos novos, todos dentro do
  `@if ($healthCheck)` já existente:
  - **Evolução do Score**: usa `$anterior` (resolvido em `mount()`) e
    `$anterior->healthCheck?->scoreResultado()`. 3 mensagens amigáveis sem
    exceção: sem `$anterior` → "Esta é a primeira importação deste tipo
    para esta obra."; `$anterior` existe mas sem Score → "A importação
    anterior não possui Score disponível para comparação."; ambos com
    Score → delta (`atual->score - anterior->score`) com ícone
    ▲/▼/■ (`bx-up-arrow-alt`/`bx-down-arrow-alt`/`bx-minus`) e frase
    "A saúde do cronograma melhorou/piorou/permaneceu igual."
  - **Indicadores da Importação**: card único com Obra/Arquivo/Usuário/
    Data/Tipo (já no cabeçalho) + Quantidade de atividades
    (`criadas+atualizadas`), Cobertura, Score, Faixa, Total de ocorrências,
    Versão das regras/do Score — tudo lido direto de colunas já
    persistidas. Duas decisões de derivação aritmética documentadas em
    comentário no próprio Blade (não são recálculo do Health Check/Score):
    "Atividades analisadas (estimado)" = `round(cobertura/100 ×
    (criadas+atualizadas))`, uma conta de exibição sobre 2 valores já
    persistidos; "Regras com ocorrências" = contagem de `regra_id`
    distintos no JSON de `findings` já gravado — deliberadamente DIFERENTE
    de "total de regras executadas" (esse número não é persistido em lugar
    nenhum e não seria seguro derivar sem reexecutar o motor).
  - **O que mudou desde a última importação**: `compararFindings()` conta
    ocorrências por `regra_id` nos dois JSONs de `findings` já persistidos
    (somando `count($finding['atividades'])`, cobre findings
    multi-ocorrência como STRUCT-004/005/LOGIC-005) e lista só os
    `regra_id` cuja quantidade mudou, com ícone de melhora
    (`bx-check-circle text-success`, quando `atual < anterior`) ou piora
    (`bx-error text-warning`). Nunca reexecuta nenhuma regra — é
    comparação pura de 2 arrays já em memória.
- **Navegação por categoria** (item 4 do pedido): cada card de "Score por
  Dimensão" virou clicável (`wire:click="filtrarCategoria('{{ valor }}')"`),
  com destaque visual (`border-primary`) na categoria ativa. Novo método
  `findingsFiltrados()` no componente filtra `$healthCheck->findings` pelo
  `categoriaFiltro` selecionado (`null` = todas) — a seção "Ocorrências do
  Health Check" passou a incluir esse array filtrado em vez do array bruto,
  reaproveitando o `health-check-findings.blade.php` **sem nenhuma
  alteração nele**. Botão "Mostrar todas as categorias" (`wire:click=
  "filtrarCategoria(null)"`) aparece nos dois cards (Score por Dimensão e
  Ocorrências) só quando há filtro ativo. **Decisão importante**: o filtro
  NÃO afeta "Mapa de Ações" nem "Indicadores da Importação" — esses dois
  cards são resumos globais da importação inteira, não uma lista navegável
  por categoria (só "Ocorrências do Health Check" é filtrada).
- **Mapa de Ações** ganhou a frase "resultado esperado" pedida (item 5):
  a linha que já mostrava "Potencial: recuperar até X pontos" virou "Após
  corrigir esta ocorrência, espera-se recuperar aproximadamente X ponto(s)
  no Score e eliminar N ocorrência(s) de '<título>' (<severidade>)." — só
  reformatação de texto sobre os MESMOS campos que `AcaoRecomendada` já
  carregava (`impacto`, `quantidadeAtividades`, `titulo`, `severidade`);
  nenhum campo novo, nenhum cálculo novo.
- **`_partials/historico-importacoes.blade.php`** ganhou a coluna "Δ Score"
  (item 6 — Timeline). Calculado em memória: 1 única query extra busca
  TODO o histórico de `CronogramaImportacao` da obra (`id`/`tipo`/
  `importado_em`/`healthCheck:score`, ordenado por `importado_em`) — não
  paginado, mas ainda 1 query, nunca N+1 — e um loop em PHP puro resolve,
  pra cada `tipo`, o score da importação anterior IMEDIATA (mesma
  semântica de `importacaoAnterior()`, sem chamar o método linha a linha).
  Ícone ▲ (verde, `+N`) / ▼ (vermelho, `N`) / ■ (cinza, `0`) / "—" (sem
  comparação possível: primeira importação do tipo, ou anterior/atual sem
  Score). **Trade-off documentado no próprio partial**: o custo é
  O(total de importações da obra), não O(linhas da página) — aceitável
  porque ainda é 1 query, mas cresce com o histórico da obra; se algum dia
  isso pesar, o próximo passo seria persistir o delta no momento da
  importação em vez de recalculá-lo a cada render do histórico.
- **`_partials/score-explicacao.blade.php`** ganhou 1 bullet novo (item 7):
  "o objetivo não é atingir 100 a qualquer custo — é reduzir os riscos do
  cronograma", reforçando que o Score não substitui o julgamento do
  planejador.
- **Compatibilidade** (item 8): todos os estados de ausência de Score/
  Health Check já tratados na Etapa 5 continuam intocados — os 3 blocos
  novos desta etapa vivem dentro do MESMO `@if ($scoreResultado)`/
  `@if ($healthCheck)` já existentes, então importação antiga (sem Score
  ou sem Health Check) nunca chega a renderizar "Evolução do Score"/"O que
  mudou" com dado inventado.
- **Performance** (item 9): zero chamada a `HealthCheckEngine`/
  `ScoreCalculator` em qualquer novo código desta etapa; `mount()` ganhou
  exatamente 1 query nova (`importacaoAnterior()`); o histórico ganhou
  exatamente 1 query nova (delta em lote, não por linha).
- Testes: `tests/Feature/ImportacaoEvolucaoScoreTest.php` (15 testes) —
  `importacaoAnterior()` isolado (resolve corretamente, não mistura tipos,
  nunca cruza obra, nunca cruza tenant), Evolução do Score nos 5 cenários
  (primeira importação, melhorou, piorou, igual, anterior sem Score,
  anterior sem Health Check), "O que mudou" (lista mudanças reais, omite
  quando não há mudança), navegação por categoria (filtra e reseta,
  verificado via `$component->instance()->findingsFiltrados()` — checar o
  HTML inteiro não funciona aqui porque "Mapa de Ações" sempre mostra
  todas as categorias, então `assertDontSee` cru dava falso-negativo),
  timeline (delta correto entre importações consecutivas do mesmo tipo,
  primeira importação sem delta). **Achado de teste**: a coluna
  `importado_em` é `timestamp` (precisão de segundo, sem frações) — dois
  imports disparados em sequência rápida num teste podem cair no mesmo
  segundo, empatando a comparação `<` estrita de `importacaoAnterior()`;
  os testes que precisam de ordem garantida backdatam explicitamente a
  importação mais antiga (`->update(['importado_em' => now()->subDays(2)])`)
  em vez de confiar na ordem real de execução. Suíte completa sem
  regressão, incluindo `TenantIsolationTest` e toda a suíte de Fase 1/2A/
  2B.1/2B.2A/2B.2B/2B.3/Score-Etapa-3/Score-Etapa-4/Score-Etapa-5/UX-fixes.

## Health Check — Fase 4 (diagnóstico) e Fase 4.1 (domínio do Plano de Ação)

- **Fase 4 foi só diagnóstico** (nenhum código alterado) — investigou como
  transformar o Mapa de Ações num Plano de Ação acompanhável ao longo do
  tempo. Decisões aprovadas pelo usuário: (1) **Modelo B** — o Plano de
  Ação sobrevive a reimportações, não pertence a uma única
  `CronogramaImportacao`; (2) identidade entre importações por
  **sobreposição de `external_uid`** (mesmo `regra_id`), sem threshold
  numérico; (3) Score projetado fica pra uma fase futura (nunca recalcula
  nem altera o Score persistido); (4) UI em página própria, futura,
  com só um atalho no Mapa de Ações; (5) **status principal enxuto**
  (Aberta/Resolvida/Cancelada) — "Agravado"/"Alterado"/"Persistente" são
  EVENTOS de reconciliação, nunca status permanentes da ação.
- **Fase 4.1 implementa só o domínio** (migrations + models + enums +
  serviço de reconciliação + testes) — **sem Blade, sem Livewire, sem
  Policy, sem integração com `ImportarCronogramaJob`** (deliberadamente
  adiada pro usuário poder validar a regra de reconciliação isolada
  antes de conectá-la ao fluxo de importação já aprovado).
- **`planos_acao`** (nova tabela, mesmo padrão de `restricoes`):
  `tenant_id`/`obra_id`/`cronograma_importacao_origem_id` (FK, todas
  `cascadeOnDelete` — a FK pra `cronograma_importacoes` segue a MESMA
  convenção já usada por `AvancoPeriodo`/`LinhaBase`/`AtividadeSnapshot`/
  `Report`/`CronogramaImportacaoHealthCheck`), `regra_id`, `titulo`/
  `recomendacao` (snapshot de texto no momento da criação — nunca relê a
  regra ao vivo depois), `responsavel_id`/`created_by_id` (nullable,
  `nullOnDelete`, autoria via `HasAuthorship`), `prazo`, `status`
  (`StatusPlanoAcao`, default `aberta`), **`uids_referencia`** (json — a
  identidade da Fase 4/Etapa 5, **atualizada a cada reconciliação**,
  nunca presa ao valor da criação), `resolvida_em`, timestamps,
  `softDeletes`. **Decisão deliberada**: sem colunas `categoria`/
  `severidade` — não estavam na lista aprovada de campos e nenhuma lógica
  de reconciliação desta fase precisa delas (severidade é constante por
  `regra_id`, não varia entre ocorrências da mesma regra); dá pra
  adicionar depois quando a UI precisar de "Prioridade", sem quebrar nada.
- **`plano_acao_reconciliacoes`** (nova tabela, append-only, mesmo
  espírito de `DocumentoEngenhariaReprogramacao`): `plano_acao_id`/
  `cronograma_importacao_id` (FK cascade), `resultado`
  (`ResultadoReconciliacaoPlanoAcao`), `status_anterior`/`status_novo`,
  `uids_anteriores`/`uids_atuais` (json), `quantidade_anterior`/
  `quantidade_atual`, `impacto_anterior`/`impacto_atual` (float,
  **nullable, sempre `null` nesta fase** — ver achado abaixo), timestamps
  (`updated_at` nunca gerenciado, `const UPDATED_AT = null` — log
  imutável). Existe porque "Agravado"/"Alterado"/"Persistente"/
  "Resolvido" (decisão do usuário) são eventos, não status permanentes —
  sem esta tabela, essa informação simplesmente desapareceria a cada
  reconciliação.
- **`StatusPlanoAcao`** (`Aberta`|`Resolvida`|`Cancelada`, com
  `estaAberta()`) e **`ResultadoReconciliacaoPlanoAcao`**
  (`Persistente`|`Agravado`|`Alterado`|`Resolvido`, com `label()`) —
  dois enums novos, mesmo estilo simples de `StatusRestricao`.
- **Achado importante, reportado ao usuário antes de codificar (Parte F
  do pedido)**: `impacto_anterior`/`impacto_atual` ficam **sempre `null`
  nesta fase**, de propósito. `AcaoRecomendada` (Mapa de Ações) não
  carrega o conjunto de `uid`s de cada ocorrência — quando uma regra
  gera múltiplas ocorrências na mesma importação (ex.: 3 ciclos
  STRUCT-005 viram 3 `AcaoRecomendada` distintas, todas com
  `regraId=STRUCT-005`), não há como casar com certeza qual impacto do
  Mapa de Ações pertence a qual ocorrência específica sem alterar
  `ScoreCalculator`/`AcaoRecomendada` — fora do escopo autorizado desta
  fase (`ScoreCalculator::calcular()` foi propositalmente NÃO tocado).
  **Quantidade**, ao contrário, é confiável e usada como critério de
  piora — extraída diretamente do finding via `UidExtractor`.
- **`App\Support\HealthCheck\PlanoAcao\UidExtractor`**: extração
  **recursiva e genérica** de todo valor associado à chave literal
  `'uid'`, em qualquer profundidade. Achado da investigação (lendo o
  código real das regras, não presumido): `HealthCheckFinding::$atividades`
  tem pelo menos 3 formas — plana (`[{uid,...}]`), agrupada por grupo
  (`[{ciclo_id|componente_id, atividades:[{uid,...}], relacoes:[...]}]`,
  STRUCT-004/005), e par (`[{predecessora:{uid,...}, sucessora:{uid,...},
  vinculos:[...]}]`, LOGIC-005/009, mais a variante com
  `predecessoras_ativas`/`predecessoras_inativas` do LOGIC-010). Em vez
  de hardcodar cada nome de chave de agrupamento (frágil a cada regra
  nova), a extração percorre QUALQUER array aninhado — cobre as 3 formas
  atuais e continua funcionando pra formas futuras, desde que a
  convenção `'uid'` (já usada por TODAS as 36 regras) se mantenha. Nunca
  modifica os findings persistidos — só lê.
- **`App\Support\HealthCheck\PlanoAcao\PlanoAcaoReconciliador`**:
  serviço isolado (`reconciliar(Collection $acoesAbertas,
  CronogramaImportacao $novaImportacao, CronogramaImportacaoHealthCheck
  $healthCheck): Collection`), NÃO integrado a `ImportarCronogramaJob`
  ainda (Parte I do pedido). Classificação **derivada literalmente dos
  exemplos do usuário**, pra eliminar a ambiguidade entre "cresceu" e
  "mudou": sejam `prevSet` (uids da última reconciliação/criação) e
  `currSet` (união dos uids de TODOS os findings da mesma `regra_id` que
  têm QUALQUER sobreposição com `prevSet` — findings da mesma regra sem
  nenhuma sobreposição são ignorados, tratados como "problema novo",
  nunca associados a esta ação):
  - `currSet` vazio -> **Resolvido** (nunca apaga a ação — só marca
    status e grava `resolvida_em`; `uids_referencia` mantém a última
    referência conhecida, nunca é limpo).
  - `prevSet ⊆ currSet` e `currSet == prevSet` -> **Persistente**.
  - `prevSet ⊆ currSet` e `currSet` estritamente maior (nada saiu, só
    cresceu) -> **Agravado**.
  - Qualquer uid de `prevSet` ausente de `currSet` (mesmo que outros
    tenham entrado) -> **Alterado** — NUNCA resolve sozinho, sempre fica
    `Aberta`, exige revisão humana (regra explícita do usuário).
  - Defesa em profundidade: uma ação passada ao reconciliador que já não
    esteja `Aberta` é ignorada silenciosamente (nenhum evento gerado) —
    nunca reabre `Resolvida`/`Cancelada` sozinha, mesmo que o chamador
    passe uma coleção "suja" por engano.
  - Todo o lote roda dentro de UM `DB::transaction()`.
- **`PlanoAcao::criarDeFinding()`** (factory estático, não um Action
  separado — evita duplicar em UI/testes): congela `titulo`/`recomendacao`
  do finding, extrai `uids_referencia` via `UidExtractor`, e valida que o
  responsável (se informado) tem vínculo com a obra
  (`$responsavel->temAcessoAObra($obraId)`, helper já existente de
  `HasObraPapel`) — lança `\InvalidArgumentException` se não tiver. Essa
  validação vive no DOMÍNIO, não só numa camada de UI futura, porque é
  uma invariante de segurança (Fase 4 diagnóstico, Etapa 9).
- **Não tocado**: `MsProjectImporter`, `HealthCheckEngine`, as 36 regras,
  `ScoreCalculator::calcular()` (nenhum método novo foi necessário nesta
  fase — Score projetado ficou pra depois), `HealthCheckResultado`,
  `ImportarCronogramaJob`, nenhuma migration existente, nenhum Blade/
  Livewire, nenhuma Policy nova (Fase 4.1, Parte J: preparado o domínio —
  `temAcessoAObra()` reaproveitado — mas Policy de verdade fica pra
  quando existir UI).
- Testes: `tests/Unit/UidExtractorTest.php` (10 testes — plana, agrupada
  STRUCT-004/005, par LOGIC-005/009/010, múltiplos grupos, uids
  repetidos em 2 formas, conjunto vazio, estrutura sem nenhum `uid`),
  `tests/Unit/PlanoAcaoTest.php` (8 testes — criação com dados
  congelados, responsável+prazo, autoria via `HasAuthorship`, uids de
  finding agrupado, responsável sem acesso à obra lança exceção,
  isolamento de tenant em 2 cenários, isolamento de obra),
  `tests/Unit/PlanoAcaoReconciliadorTest.php` (16 testes — os 4
  resultados com finding plano E agrupado, finding de regra diferente
  não associa, múltiplos grupos na mesma regra só considera o grupo com
  sobreposição, uids repetidos não inflam quantidade, `atividades` vazio
  tratado como sem sobreposição, ação já resolvida/cancelada nunca
  reconciliada de novo, snapshot de Health Check e Score persistidos
  permanecem intactos após reconciliação, evento grava dados suficientes
  pra auditoria). 34 testes novos no total. Suíte completa sem
  regressão, incluindo `TenantIsolationTest` e toda a suíte de Fase 1/
  2A/2B.1/2B.2A/2B.2B/2B.3/Score-Etapa-3/4/5/Fase-3.1/UX-fixes.
- **Não avançar pra integração com `ImportarCronogramaJob`, Policy, UI
  ou Blade sem validação do usuário** (instrução explícita) — aguardando
  aprovação desta etapa antes de continuar pra Fase 4.2.

## Health Check — Fase 4.2 (integração real + criação pela UI)

- **Contexto**: liga o domínio da Fase 4.1 (migrations/models/enums/
  `PlanoAcaoReconciliador`, isolado e não integrado de propósito) ao fluxo
  real de importação, e permite criar uma ação a partir de um item do Mapa
  de Ações direto na tela de detalhe da importação. Nenhuma regra da Fase
  1/2, nenhuma matemática de `ScoreCalculator::calcular()`, nenhum snapshot
  histórico de Health Check/Score foi alterado.
- **Integração no `ImportarCronogramaJob`**: dentro do MESMO
  `if ($this->healthCheckSerializado !== null)` e da MESMA transação já
  existente, logo depois de `CronogramaImportacaoHealthCheck::create(...)`
  (capturado numa variável) — busca `PlanoAcao::where('obra_id',
  $this->obra->id)->where('status', Aberta)->get()` (tenant já garantido
  por já estar dentro de `TenantContext::actingAs()`) e, se não-vazio,
  chama `PlanoAcaoReconciliador::reconciliar()`. **Roda pra Baseline E
  Avanço** (decisão do usuário: o Plano de Ação acompanha o PROBLEMA
  TÉCNICO, não o tipo de importação — deliberadamente DIFERENTE da regra
  de "Evolução do Score" da Fase 3.1, que continua exigindo mesmo tipo
  pra comparar dois números). Uma falha na reconciliação propaga e desfaz
  a transação INTEIRA (importação + Health Check + Score + reconciliação),
  mesmo risco já aceito pra Health Check/Score desde a Fase 1.
- **Achado confirmado por código, não hipotético (o "ponto crítico" do
  diagnóstico)**: `HealthCheckFinding::quantidade()` retorna
  `count($this->atividades)` — pra STRUCT-004/STRUCT-005, `$atividades` é
  SEMPRE um array de 1 elemento (o descritor do grupo), então
  `quantidade()` = 1 sempre, não importa o tamanho do ciclo/componente.
  Como `ScoreCalculator::montarMapaAcoes()` gera uma `AcaoRecomendada` POR
  FINDING (não por `regra_id` agregada), **duas ocorrências distintas da
  mesma regra (ex.: 2 ciclos separados) produziam `AcaoRecomendada`
  byte-a-byte IDÊNTICAS** — sem nenhum campo pra saber qual veio de qual
  finding. Resolvido com a Opção A do diagnóstico (aprovada explicitamente
  pelo usuário, já que contrariava a instrução "não alterar
  ScoreCalculator" de fases anteriores):
  - **`AcaoRecomendada` ganhou `findingIndex: ?int`** (aditivo, default
    `null`) — a posição original do finding em
    `HealthCheckResultado::$findings` (mesma ordem persistida em
    `cronograma_importacao_health_checks.findings`).
  - **`ScoreCalculator::montarMapaAcoes()`**: `array_filter()` já preserva
    as chaves de `$impactos` (== índice original), mas o `array_values()`
    antigo descartava isso antes do `usort()` (que sempre reindexa) — a
    chave agora é capturada num 3º elemento da tupla ANTES do sort e
    repassada como `findingIndex` no `AcaoRecomendada` final. **Nenhuma
    fórmula, filtro, critério de ordenação ou número muda** — mesmo Score/
    faixa/cobertura/`porDimensao`/`potencialRecuperavel`/`impacto` de
    sempre, só uma referência de rastreabilidade a mais no JSON.
    `calcular()` em si não precisou de nenhuma mudança (já preservava as
    chaves via `array_map()` de um único array).
  - `AcaoRecomendada::fromArray()` trata ausência de `finding_index` (JSON
    de registros anteriores a esta fase) como `null` — nunca inventa um
    índice retroativo.
- **Duplicação controlada** (`PlanoAcao::criarDeFinding()`, decisão do
  usuário com uma ressalva importante): bloqueia a criação SÓ quando já
  existe uma ação `Aberta` da MESMA obra + `regra_id` cujo
  `uids_referencia` tem sobreposição com o finding usado agora — MESMO
  critério de identidade do `PlanoAcaoReconciliador`
  (`SobreposicaoUid::temSobreposicao()`, extraído como helper público
  reaproveitado nos dois lugares — nunca uma heurística nova). Findings
  distintos da mesma regra SEM sobreposição entre si (ex.: o exemplo
  literal do diagnóstico, Finding A `[1,2,3]` e Finding B `[10,11]`)
  coexistem livremente, cada um pode virar sua própria ação. Lança
  `App\Exceptions\PlanoAcaoDuplicadoException` (nova, carrega a ação
  existente) — a UI traduz isso numa mensagem amigável dentro do modal,
  nunca uma exceção crua na tela.
- **`App\Support\HealthCheck\PlanoAcao\SobreposicaoUid`** (novo, único
  ponto de verdade pra "esses dois conjuntos de uid representam o mesmo
  problema?"): reaproveitado por `PlanoAcaoReconciliador` (refatorado pra
  delegar, mesmo comportamento, testes da Fase 4.1 continuam passando sem
  alteração), por `PlanoAcao::criarDeFinding()` (duplicação) e pela UI
  (badge "N ações abertas").
- **`PlanoAcaoReconciliador` deixou de ser `final`** — decisão adicional
  necessária durante a implementação (não estava no plano aprovado):
  Mockery não consegue gerar um double de uma classe `final` sem
  interface, e o teste de rollback completo (Parte 7 do pedido) precisa
  mockar `reconciliar()` pra forçar uma falha simulada dentro da
  transação do Job. Nenhum comportamento muda — a classe continua sem ser
  estendida em nenhum lugar do código de produção.
- **Slug `restricoes.plano_acao`** (novo, `ESCOPO_OBRA`, seção
  "Restrições") — deliberadamente independente de
  `obras.importar_cronograma` (decisão do usuário: responsabilidades
  diferentes). `App\Policies\PlanoAcaoPolicy` segue o mesmo molde de
  `CronogramaImportacaoPolicy`/`ReportPolicy` (`view`/`update`/`delete`
  recebem o model; `create` recebe `obra_id` direto, já que a ação ainda
  não existe). **Decisão adicional necessária, não explicitamente
  pedida**: `Perfil::REGRAS_ESCRITA` precisou de uma entrada nova pro
  slug (senão NENHUM perfil, nem Admin, ganharia `criar`/`editar`/
  `excluir` via `seedPadrao()`) — usado o mesmo limiar de
  `restricoes.quadro` (Encarregado/Engenheiro/GerentePlanejamento), a
  página irmã mais próxima em espírito.
- **UI — card "Mapa de Ações" (`⚡importacao-detalhe.blade.php`), sem
  nenhuma página nova**: cada item ganhou um botão "Criar Ação" (`@can`
  em `restricoes.plano_acao|criar`) + badge "N ação(ões) aberta(s)"
  quando aplicável (calculado via `SobreposicaoUid` contra o
  `uids_referencia` de ações abertas da mesma regra). Itens com
  `findingIndex === null` (Score calculado ANTES desta fase — registro
  histórico) mostram uma mensagem amigável em vez do botão, nunca um
  erro. Modal de criação segue o padrão de `⚡restricoes.blade.php`
  (`@if($propriedadeBooleana) <div class="modal fade show d-block">`,
  sem depender de JS do Bootstrap) — só 2 campos (responsável, prazo),
  responsável vindo de `$obra->users()` (mesma fonte já usada em
  Restrições, garante que o `<select>` nunca lista alguém sem acesso à
  obra — defesa em profundidade, já que `criarDeFinding()` valida isso de
  novo no domínio). Exceções (`PlanoAcaoDuplicadoException`,
  `InvalidArgumentException`) são capturadas no método Livewire e viram
  mensagem dentro do modal — nunca propagam pra tela.
- **Não tocado**: `MsProjectImporter`, `HealthCheckEngine`, as 36 regras,
  `ScoreCalculator::calcular()` (só `montarMapaAcoes()`, aditivo),
  `HealthCheckResultado`, nenhuma migration existente, snapshots
  históricos de Health Check/Score, o restante do detalhe da importação
  (Evolução do Score/Indicadores/O que mudou/Score por Dimensão/
  Ocorrências), notificações (não implementadas nesta fase, por pedido
  explícito).
- Testes: `tests/Unit/ScoreCalculatorTest.php` (+6 — `findingIndex`
  aponta pro índice certo, distingue múltiplas ocorrências da mesma
  regra, não afeta nenhum número de Score/faixa/cobertura/potencial/
  `porDimensao`, `null` em DTOs antigos, round-trip `toArray`/
  `fromArray`), `tests/Feature/PlanoAcaoDuplicacaoTest.php` (5 — bloqueio,
  coexistência, regra diferente não conta, ação resolvida não bloqueia,
  escopo por obra), `tests/Feature/PlanoAcaoReconciliacaoIntegracaoTest.php`
  (12 — reconciliação executa em Baseline e Avanço via `ImportarCronogramaJob::
  dispatchSync()` real, os 4 resultados com UIDs reais do fixture,
  isolamento de obra/tenant, importação sem Health Check, rollback
  completo com Mockery, imutabilidade do Health Check/Score da importação
  anterior), `tests/Feature/ImportacaoDetalheCriarAcaoTest.php` (7 —
  permissão, criação com `findingIndex`, duplicação, responsável sem
  acesso, badge, cancelar). 30 testes novos no total. Suíte completa sem
  regressão, incluindo `TenantIsolationTest` e toda a suíte de Fase 1
  até 4.1.
- **Não avançar pra Fase 4.3 (página própria do Plano de Ação, edição/
  cancelamento de ações, notificações) sem validação do usuário**
  (instrução explícita).

## Health Check — Fase 4.3 (Plano de Ação: listagem, painel, edição)

- Página própria `radar.plano-acao` (Etapa B), painel expansível inline
  somente-leitura (Etapa C) e edição manual de responsável/prazo/status
  (Etapa D) — documentação completa de todas as etapas fica pra Etapa F
  (relatório final da Fase 4.3), registrado aqui só o ponto explicitamente
  pedido nesta etapa:
- **Lacuna de auditoria conhecida, registrada deliberadamente**: alterações
  manuais de responsável, prazo e status (Etapa D,
  `PlanoAcao::confirmarEditar()` em `⚡plano-acao.blade.php`) ainda não
  possuem auditoria de usuário/data — não existe nenhum mecanismo de log
  genérico no projeto (nenhum pacote de activity log, nenhuma tabela
  equivalente em nenhum outro domínio), e `plano_acao_reconciliacoes`
  **não é** essa auditoria (representa reconciliação automática contra
  importação, não edição administrativa — misturar os dois conceitos foi
  explicitamente rejeitado). Isso deverá ser tratado em etapa futura
  específica, não implementado aqui.
- **Etapa E — menu + integração com o Mapa de Ações**: item "Plano de Ação"
  adicionado em `resources/menu/verticalMenu.json` (seção "2. RESTRIÇÕES",
  último item — mesma seção da `funcionalidade` `restricoes.plano_acao` no
  `CatalogoFuncionalidades`, ícone `bx-task` reaproveitado do próprio
  cabeçalho da página), visível via o MESMO mecanismo já existente
  (`CatalogoFuncionalidades::usuarioPodeVer()`) — nenhuma lógica de
  visibilidade nova.
- **Badge "N ação(ões) aberta(s)" do Mapa de Ações
  (`⚡importacao-detalhe.blade.php`) virou link** pra
  `route('radar.plano-acao', ['regra' => $finding->regraId])` quando
  `$qtdAbertas > 0` (nunca quando `=== 0`, que continua sem badge nenhum,
  como já era). **Decisão de implementação**: o link NÃO inclui um
  parâmetro `obra` — a rota `radar.plano-acao` não tem `{obra}` como
  segmento (obra sempre vem do `ObraContext`/sessão via o middleware
  `obra.context`, mesmo mecanismo de todo link `radar.*` do projeto,
  nunca um novo mecanismo de contexto). Limitação conhecida, aceita
  deliberadamente: como `RequireObraContext` permite acessar o detalhe de
  uma importação de uma obra diferente da obra ativa em sessão (a Policy
  só valida vínculo com a obra DONA da importação, não que seja a obra
  ativa), num cenário raro em que o usuário está vendo o detalhe de uma
  importação de uma obra diferente da ativa, o link levaria pro Plano de
  Ação da obra ATIVA, não da obra da importação — mesma classe de
  limitação que já existiria em qualquer link `radar.*` de dentro dessa
  tela; não construída nenhuma lógica de troca de obra pra cobrir esse
  caso raro (fora do escopo aprovado desta etapa).
- **`⚡plano-acao.blade.php::$filtroRegraId` ganhou `#[Url(as: 'regra')]`**
  — só o atributo, `queryFiltrada()`/`aplicarOrdenacao()`/paginação/
  eager-loading/badges/painel/edição/transições intocados. Permite
  `/app/radar/plano-acao?regra=X` chegar com o filtro já preenchido.
- **N+1 corrigido no Mapa de Ações**: `contarAcoesAbertasParaFinding()`
  rodava 1 query em `planos_acao` POR finding exibido (até N queries pra N
  itens do Mapa de Ações). Corrigido com `#[Computed] acoesAbertasDaObra()`
  — busca TODAS as ações Abertas da obra numa única query, e a contagem
  por finding passou a filtrar essa MESMA coleção em memória (mesmo
  critério `SobreposicaoUid`, nenhuma heurística nova — nunca
  `regra_id + quantidadeAtividades`). **Achado de implementação**:
  `#[Computed]` só cacheia quando acessado via sintaxe de propriedade
  (`$this->acoesAbertasDaObra`, sem parênteses) — chamar como método
  normal (`$this->acoesAbertasDaObra()`) executa a query de novo a cada
  chamada, silenciosamente ignorando o cache. Descoberto porque o teste
  de contagem de queries (`DB::listen`) pegou 2 queries em vez de 1 na
  primeira rodada — corrigido trocando pra sintaxe de propriedade.

## Health Check — Ciclo 10 (transparência de aplicabilidade Baseline)

- **Contexto**: correção de UX derivada da revisão funcional/arquitetural
  pós-Ciclos 1-9 (separação Planejamento/Execução) — o card "Score por
  Dimensão" mostrava "Score 100 · 0 ocorrências" de forma idêntica tanto
  pra uma categoria genuinamente avaliada e limpa quanto pra uma categoria
  cujas regras nem chegaram a rodar (ex.: categoria Avanço Físico, 100%
  Execução, numa importação Baseline). **App\Support\HealthCheck\
  AplicabilidadeCategoria** (novo, camada de apresentação — nunca de
  domínio de Score) resolve isso calculando, por categoria, quantas
  regras eram aplicáveis ao tipo desta importação, e classifica em 3
  estados (`App\Enums\EstadoAplicabilidadeCategoria`): **Avaliada** (todas
  aplicáveis), **Parcialmente avaliada** (algumas — Datas/HH/Marcos/
  Lógica são categorias mistas, com regras de Planejamento E de Execução
  ao mesmo tempo) e **Não avaliada** (nenhuma — Avanço Físico é a única
  categoria 100% Execução).
- **A aplicabilidade das regras por tipo de importação é derivada em
  tempo de leitura a partir do catálogo atual de regras, não persistida
  no snapshot.** Isso preserva o escopo aditivo e evita migration, mas
  significa que eventual mudança futura da natureza de uma regra poderá
  alterar a classificação visual de importações históricas. Se
  reclassificações de natureza se tornarem frequentes, deverá ser
  considerada a persistência da lista de regras avaliadas.
- **`HealthCheckEngine::catalogoRegras()`** (novo, introspecção pura,
  mesmo espírito de `naturezaDaRegra()` da Fase 4.2 — nunca usado por
  `avaliar()`/`regrasFiltradas()`): expõe categoria+natureza de todas as
  36 regras, indexado por `regra_id`. As regras "por atividade"
  (`HealthCheckRuleInterface`) já expõem `categoria()` no próprio
  contrato, lidas polimorficamente; as 12 regras estruturais
  (`HealthCheckRegraEstruturalInterface`, Fase 2B) não têm `categoria()`
  no contrato — cada uma hardcoda a categoria dentro do próprio
  `avaliar()` — então são mapeadas explicitamente por `regra_id` numa
  constante privada (`CATEGORIA_REGRAS_ESTRUTURAIS`), verificada contra o
  valor real por teste dedicado (roda `avaliar()` de verdade e confirma
  que o mapa nunca diverge).
- **`AplicabilidadeCategoria::calcularTodas()`** reaproveita o MESMO
  predicado de `HealthCheckEngine::regrasFiltradas()` ("Baseline só avalia
  Planejamento") — sem tocar o método original, protegido por teste
  dedicado, nunca um mapa paralelo de classificação.
- **UI**: `⚡importacao-detalhe.blade.php` — card "Score por Dimensão"
  ganhou um badge de aplicabilidade (só visível quando não é "Avaliada",
  pra não poluir Avanço/Ambos, onde as 10 categorias são sempre
  Avaliada); `score-explicacao.blade.php` ganhou 3 itens explicando a
  separação Baseline/Avanço e o significado de "não avaliada" (nem
  saudável, nem problemática). Cores do badge deliberadamente neutras
  (nunca reaproveitam vermelho/laranja de severidade) — aplicabilidade
  não é um julgamento de saúde do cronograma.
- **Plano de Ação**: card "Mapa de Ações" ganhou um aviso informativo,
  só em Baseline e só quando existem ações Abertas originadas de regra de
  Execução (`acoesExecucaoAguardandoAvanco`, reaproveita
  `$this->acoesAbertasDaObra` já carregada — nenhuma query nova).
  Puramente informativo — nunca altera, resolve ou reabre nenhuma ação;
  `PlanoAcaoReconciliador` não foi tocado.
- **Não alterado nesta etapa**: `ScoreCalculator`, `ScoreDimensao`,
  `ScoreResultado`, nenhuma das 36 regras, `HealthCheckEngine` no
  comportamento de avaliação (`avaliar()`/`regrasFiltradas()`),
  `PlanoAcaoReconciliador`, `DiagnosticoReport`, `ReportGerador`,
  `MsProjectImporter`, Lookahead, nenhuma migration.

## Health Check — Ciclo 11 (ponte PlanoAcao → Restrição)

- **Contexto**: 5 rodadas de investigação 100% somente-leitura (Etapas A,
  A.1, A.2, B.0, B.1) precederam a implementação — avaliaram e
  descartaram criação automática de Restrição a partir de Finding/
  importação (risco de falso positivo documentado no próprio docblock de
  `SLACK-001`: folga negativa "NÃO é necessariamente erro de
  planejamento"), confirmaram que `PlanoAcao`/`Restricao` nunca tiveram
  nenhum acoplamento antes desta fase, e recomendaram a arquitetura
  implementada aqui: `Health Check Finding → PlanoAcao → decisão humana
  explícita → Restrição por atividade`. **Nenhuma sincronização de ciclo
  de vida** entre os dois: resolver/cancelar/reabrir um `PlanoAcao` nunca
  toca as Restrições que ele originou, e vice-versa — são conceitos
  relacionados mas não equivalentes (`PlanoAcao` responde se o
  diagnóstico do Health Check continua presente; `Restricao` responde se
  existe impedimento operacional pra comprometimento/prontidão).
- **`restricoes.origem_plano_acao_id`** (nullable FK →
  `planos_acao.id`, `nullOnDelete`, mesmo padrão de
  `origem_suprimento_item_id` já existente) + índice
  `UNIQUE(tenant_id, origem_plano_acao_id, atividade_id)`
  (`restricoes_origem_plano_acao_atividade_unique`, nome curto explícito
  — mesmo motivo já documentado no projeto pro limite de 64 chars do
  MySQL). Protege só a combinação PlanoAcao+Atividade — nunca a
  atividade isoladamente: `NULL` nunca colide consigo mesmo no MySQL,
  então Restrições manuais e de Suprimento (sempre
  `origem_plano_acao_id = null`) continuam livres pra coexistir na mesma
  atividade, exatamente como já documentado pra Suprimento.
- **`PlanoAcao::transformarEmRestricoes(array $atividadeIdsSelecionados): array`**
  (domínio, mesmo espírito de `criarDeFinding()`): cria no máximo 1
  Restricao por atividade selecionada. **Revalida tudo no servidor** — a
  lista recebida é só a intenção do usuário (checkboxes marcados no
  Alpine), nunca fonte confiável: (i) atividade precisa pertencer à
  MESMA obra do PlanoAcao; (ii) seu `external_uid` precisa estar em
  `uids_referencia` (mesma identidade já usada por
  `atividadesRelacionadas()`, não um id solto forjável); (iii) não pode
  estar `fora_do_cronograma` (ignorada silenciosamente, nunca vira
  Restrição "inativa"/"resolvida" — decisão do usuário); (iv) não pode
  já ter Restricao originada deste MESMO PlanoAcao (ignorada
  silenciosamente, contabilizada como "já vinculada"). Retorna contagens
  (`criadas`/`ignoradasForaDoCronograma`/`ignoradasJaVinculadas`/
  `ignoradasInvalidas`) pra UX informar o resultado sem nunca expor erro
  técnico cru. **Corrida concorrente**: a proteção definitiva é o índice
  UNIQUE — um `\Illuminate\Database\QueryException` com `errorInfo[1] ===
  1062` (MySQL, duplicate entry) é capturado e tratado como "já
  vinculada", nunca propagado cru; qualquer outro código de erro sobe
  normalmente. Defaults da Restrição criada: `bloqueante = false`
  (usuário promove manualmente depois, se decidir), `categoria_id =
  null` (sem pilar Lean novo criado nesta fase — decisão do usuário),
  `status = Aberta`, `aberta_em = now()`. **Nunca cria `RestricaoAcao`**
  — decisão do usuário: a origem já é 100% rastreável via
  `origem_plano_acao_id` + os timestamps da própria Restricao, sem
  mecanismo de auditoria paralelo (mesmo achado já documentado: a
  criação MANUAL de Restrição em `⚡restricoes.blade.php` também nunca
  cria `RestricaoAcao` — só `resolver()` cria, com o texto de
  justificativa do usuário).
- **Dupla autorização, obrigatória e sequencial**: `$this->authorize
  ('update', $acao)` (PlanoAcaoPolicy) seguido de `$this->authorize
  ('create', [Restricao::class, $acao->obra_id])` (RestricaoPolicy) —
  nenhuma Policy existente foi alterada. Ter permissão num domínio NÃO
  implica ter no outro (`restricoes.plano_acao` e `restricoes.quadro`
  são slugs independentes) — qualquer uma falhando lança
  `AuthorizationException` imediatamente, antes de qualquer Restrição
  ser criada (nunca criação parcial).
- **UI** (`_partials/plano-acao-detalhe.blade.php`, seção "Atividades
  Relacionadas"): checkbox por atividade elegível; "Fora do cronograma —
  não elegível" (sem checkbox) pras arquivadas; "Restrição: {status}"
  (sem checkbox) pras já vinculadas a este PlanoAcao; "Atividade não
  encontrada" (já existente, sem checkbox) pros uids órfãos. Botão
  "Transformar selecionadas em Restrição" só aparece com as duas
  permissões (`$paDetPodeTransformar`), desabilitado (`x-bind:disabled`)
  sem nenhuma seleção. **Seleção é 100% Alpine local**
  (`selecionadas: []`, estendendo o `x-data="{ aberto: false }"` já
  existente do `<tbody>` em `⚡plano-acao.blade.php`) — só um
  `$wire.call('transformarEmRestricoes', acaoId, selecionadas)` no
  clique do botão, nunca um `wire:model` por checkbox (evitaria round-
  trips desnecessários por clique).
- **N+1 evitado desde a primeira versão** (achado durante a
  implementação, não depois): a primeira versão consultava
  `fora_do_cronograma` e Restrições vinculadas **por linha** dentro do
  partial — passava isolado, mas quebrava
  `PlanoAcaoPainelTest::test_query_count_nao_escala_com_total_de_acoes_da_obra_apenas_com_perpage`
  (teste pré-existente da Fase 4.3, achado de N+1 anterior). Corrigido
  batendo as DUAS consultas 1x pra TODA a página, nunca por linha —
  `⚡plano-acao.blade.php` ganhou `#[Computed] foraDoCronogramaPorUid()`
  (usa `uids_referencia`, já em memória em cada `$acao`, pra nunca
  precisar chamar `atividadesRelacionadas()` de novo) e `#[Computed]
  restricoesVinculadasPorAcao()` (agrupada por `origem_plano_acao_id`),
  mesmo padrão já usado em `acoesAbertasDaObra()` (Fase 4.3). O partial
  passou a receber os dois resultados prontos via `@include(...)`, sem
  nenhuma query própria — `atividadesRelacionadas()` continua sendo
  chamada 1x por linha exatamente como antes (não tocada, fora do
  escopo desta fase).
- **`PlanoAcaoPainelTest::test_painel_fechado_inicialmente`** precisou de
  1 linha de ajuste (`assertSeeHtml`) — o `x-data` do `<tbody>` mudou de
  `{ aberto: false }` pra `{ aberto: false, selecionadas: [] }`, mudança
  de HTML legítima e esperada desta fase, não uma quebra de
  comportamento.
- **Não implementado nesta fase** (deliberadamente, por instrução
  explícita do usuário): criação automática de Restrição a partir de
  Finding/importação/reconciliação/mudança de status do PlanoAcao,
  auto-resolução, auto-reabertura, sincronização de status/prazo/
  responsável entre PlanoAcao e Restricao, alteração de `bloqueante`
  após a criação, categoria/pilar Lean novo, rota de detalhe individual
  de Restrição (não existe hoje — só o quadro/lista), deep-link
  PlanoAcao↔Restrição.
- **Não tocado**: `HealthCheckEngine`, as 36 regras, `PlanoAcaoReconciliador`,
  `Atividade::estaPronta()`/`scopeProntas()`/`scopeNaoProntas()`,
  `ScoreCalculator`/`ScoreDimensao`/`ScoreResultado`, `DiagnosticoReport`,
  `ReportGerador`, `MsProjectImporter`, Lookahead,
  `SincronizarRestricaoSuprimento`, `StatusPlanoAcao`, `StatusRestricao`,
  `PlanoAcaoPolicy`, `RestricaoPolicy`, `PlanoAcao::atividadesRelacionadas()`.
- Testes: `tests/Feature/PlanoAcaoTransformarEmRestricaoTest.php` (18
  testes novos — criação individual/lote, `fora_do_cronograma`, uid sem
  atividade, duplicidade, proteção UNIQUE a nível de banco, idempotência
  de chamadas sequenciais, as duas permissões isoladamente, usuário de
  outro tenant, seleção vazia, ausência de `RestricaoAcao`, PlanoAcao
  intacto, campos default corretos, isolamento de obra/tenant,
  coexistência com Restrição manual e de Suprimento na mesma atividade,
  mensagem de toast com contagens). Suíte completa: **1607 passed / 6
  skipped, 2 failures pré-existentes e sem relação** (`DocumentosEngenhariaDashboardTest`/
  `ItemSuprimentoStatusTest`, fixtures com data relativa — mesmas 2
  falhas já documentadas em fases anteriores), incluindo
  `TenantIsolationTest` e toda a suíte de Plano de Ação/Restrições/
  Suprimentos sem nenhuma regressão.

## Importação Segura — Health Check (Fase 1)

- **Contexto**: pedido do usuário pra transformar a importação de
  cronograma num processo mais seguro — analisar a coerência do arquivo
  ANTES da confirmação definitiva, mostrar os problemas encontrados e
  deixar o usuário decidir entre abortar ou importar mesmo assim. Regra
  de ouro do pedido: **não reescrever o importador existente**
  (`MsProjectImporter`/`ImportadorCronograma`/`PlanoImportacao`/
  `TarefaImportada`/`HorasPeriodo`, a transação de `aplicar()`, o
  comportamento de rollback) — o Health Check é um módulo complementar,
  não uma refatoração.
- **Achado que definiu a arquitetura**: `analisar()` já era 100%
  read-only e síncrona (retorna `PlanoImportacao` em memória) e `aplicar()`
  já era atômica dentro de `DB::transaction()` — as duas telas Livewire
  (`⚡cronograma.blade.php` seção Obra, `⚡relatorio-importar-avanco.blade.php`
  seção Relatórios) já mostravam uma prévia (criar/atualizar/arquivar)
  ANTES de confirmar. A separação análise/persistência que o pedido
  descrevia já existia — o Health Check só precisava rodar dentro dessa
  janela já existente, sem tocar `MsProjectImporter` em nenhuma linha.
- **`App\Support\HealthCheck\`**: `HealthCheckEngine` (orquestra),
  `HealthCheckRuleInterface` (contrato — `bloqueante()` sempre `false`
  na Fase 1, decisão do usuário: nenhuma regra bloqueia a importação
  automaticamente, mesmo severidade Crítico), `RegraHealthCheckBase`
  (classe base pra regras que filtram `TarefaImportada` uma a uma —
  cobre a maioria), `HealthCheckFinding` (1 regra × N atividades
  afetadas), `HealthCheckResultado` (agrega, `scorePreliminar()`
  retorna `null` de propósito — decisão do usuário de NÃO calcular Score
  ainda, pra evitar um número aparentemente preciso baseado só em soma
  de pesos por severidade; a estrutura pro cálculo futuro já existe em
  `HealthCheckSeveridade::peso()`, só não é usada). Toda regra consome
  **só** o `PlanoImportacao` já produzido por `analisar()` — nenhuma
  regra da Fase 1 faz query nova no banco nem relê o arquivo.
- **23 regras em 7 categorias** (`Rules/{Datas,Avanco,Hh,Duracao,
  CaminhoCritico,Marcos,Baseline}/`): Datas (DATE-001..006), Avanço
  Físico (PROG-001..004), HH (WORK-001..006), Duração (DUR-001..002),
  Caminho Crítico (CRIT-001, regra global — nenhuma atividade crítica no
  cronograma inteiro), Marcos (MILE-001..002, usa `isMarco` já
  capturado), Baseline (BASE-002 atividades arquivadas — reaproveita
  `$plano->removerNomes` já calculado; BASE-003 baseline incompleta).
  Deliberadamente **fora de escopo da Fase 1** (dependem de dado que o
  importador não lê hoje — predecessoras/sucessoras, calendários,
  slack/folga, alocação de recursos): toda a categoria Lógica, Folgas,
  Calendários, Recursos, Produtividade, Pesos, e as regras estruturais
  de rede (isoladas/desconectadas) — ficam pra quando o parser do XML
  for estendido (aí sim precisaria de aprovação prévia, por tocar
  `MsProjectImporter`). `BASE-001` (atividades novas) foi cogitada mas
  descartada: toda importação nova tem `criar` não-vazio, então isso
  nunca seria uma "inconsistência" de verdade, só ruído.
- **Severidade**: 🔴 Crítico / 🟠 Alto / 🟡 Médio / 🟢 Baixo / 🔵
  Informativo — nenhuma bloqueia. Um finding **Informativo** conta
  como "alerta" pra fins de UI (aciona o fluxo de confirmação extra),
  decisão deliberadamente conservadora pra Fase 1 — nunca esconder nada
  do usuário, mesmo o de menor severidade.
- **`cronograma_importacao_health_checks`** (nova tabela, 1:1 com
  `cronograma_importacoes` via `cih_importacao_id_fk`/
  `cih_importacao_id_unique` — nomes de constraint explícitos porque o
  nome longo da tabela estourava o limite de 64 chars do MySQL nos
  nomes automáticos do Laravel, mesma classe de problema já documentada
  em `feedback_limite_identificador_mysql`). Guarda totais por
  severidade, `total_ocorrencias`, `importado_com_alertas` (bool),
  `versao_regras` e o JSON completo de `findings`. Como
  `cronograma_importacoes` já É a revisão/versão do cronograma, isso já
  dá de graça o histórico "Revisão 10 → resultado do Health Check,
  Revisão 11 → ..." pedido pelo usuário — sem inventar um conceito novo
  de versão.
- **O resultado persistido é EXATAMENTE o que o usuário viu, nunca
  recalculado**: `HealthCheckEngine::avaliar()` roda uma única vez,
  dentro de `analisar()` do componente Livewire (`⚡cronograma.blade.php`/
  `⚡relatorio-importar-avanco.blade.php`), logo depois de montar o
  `PlanoImportacao` — o resultado serializado (`HealthCheckResultado::
  toArray()`) fica numa propriedade Livewire (`$healthCheckResultado`) e
  é reaproveitado até a persistência. Quando o usuário confirma,
  `confirmar()` passa esse array já pronto pro
  `ImportarCronogramaJob::dispatch(..., healthCheckSerializado: ...)` —
  um parâmetro novo, opcional, no fim do construtor (`?array
  $healthCheckSerializado = null`), sem quebrar nenhum outro chamador.
  O Job **não recalcula** o Health Check — só persiste o que recebeu.
- **Persistência só acontece se a importação for concluída** (decisão
  do usuário): `ImportarCronogramaJob::handle()` passou a envolver as
  chamadas já existentes de `analisar()`+`aplicar()` (que o Job já fazia
  — comportamento preservado, incluindo o Job re-parsear o arquivo do
  zero em vez de reaproveitar o `PlanoImportacao` da prévia, que
  continua como estava) dentro de um `DB::transaction()` **do próprio
  Job**, e cria o registro de `CronogramaImportacaoHealthCheck` dentro
  desse mesmo closure, logo depois de `aplicar()` retornar. Como
  `aplicar()` já abre sua própria transação internamente, essa é uma
  transação ANINHADA (Laravel/PDO usam savepoint) — se qualquer coisa
  falhar em qualquer ponto (inclusive na criação do Health Check), a
  transação externa nunca comita e TUDO reverte junto, inclusive as
  atividades/pacotes/avanço já gravados por `aplicar()`. Nenhuma linha
  de `MsProjectImporter.php` foi tocada pra conseguir isso — só a
  orquestração de chamadas dentro do Job.
- **UI**: nova seção "Health Check — Análise de Coerência do
  Cronograma" inserida ANTES do card de prévia já existente (que
  continua 100% intacto), nas duas telas de importação — mesma ordem
  pedida (identificação do arquivo → Health Check → resumo por
  categoria/severidade → prévia já existente → ações). Cada finding é
  expansível (`data-bs-toggle="collapse"`) mostrando descrição/impacto/
  recomendação + tabela das atividades afetadas. Botão final: sem
  alertas, continua `wire:click="confirmar"` normal ("Confirmar
  importação"); com alertas, vira `onclick="confirmarAcao(...)"`
  reaproveitando o modal genérico já existente
  (`resources/views/components/confirmacao-acao.blade.php`, mesmo
  padrão de todo o resto do sistema) com o texto "Importar mesmo
  assim"/aviso de inconsistências antes de chamar `confirmar()` de
  verdade. Botão "Cancelar" não foi renomeado (já cobre "abortar" —
  decisão de risco mínimo, sem mexer no que já funcionava).
- **Cancelar continua 100% seguro sem mudança nenhuma**: como o Health
  Check roda inteiramente dentro da janela de `analisar()` (antes de
  qualquer escrita), e `cancelar()` já só apagava o arquivo temp e
  resetava estado do Livewire, abortar depois de ver alertas nunca
  toca o banco — comportamento herdado de graça da arquitetura
  existente, não precisou de nenhum código novo de segurança.
- Testes: `tests/Unit/HealthCheckEngineTest.php` (29 testes, uma por
  regra + agregação de severidade/categoria + `scorePreliminar()` null +
  round-trip `toArray()/fromArray()`, tudo com `PlanoImportacao`/
  `TarefaImportada` construídos à mão, sem banco). `tests/Feature/
  CronogramaHealthCheckTest.php` (7 testes cobrindo os 12 cenários
  pedidos): XML sem alertas, XML com alertas, abortar sem persistência,
  confirmar com alertas persiste Health Check idêntico ao exibido, falha
  simulada dentro de `aplicar()` (importador fake que quebra só nesse
  método) reverte tudo e não deixa Health Check órfão, regressão
  confirmando que `MsProjectImporter::aplicar()` continua criando as
  mesmas atividades de sempre, e o fluxo completo de Avanço (segunda
  tela). Um teste pré-existente
  (`CronogramaImportacaoLivewireTest::test_confirmar_desabilita_botoes_e_mostra_status_enquanto_job_nao_termina`)
  precisou de ajuste — usava `cronograma_sample.xml`, que legitimamente
  dispara 2 alertas (PROG-001/WORK-004) sob as novas regras, então o
  botão de confirmar virou `onclick="confirmarAcao(...)"` em vez de
  `wire:click="confirmar"` direto; o teste passou a checar
  `wire:target="confirmar"` (presente nos dois casos) em vez do atributo
  `wire:click` específico. **Achado durante os testes**: nome de
  constraint FK/unique com o nome completo da tabela
  (`cronograma_importacao_health_checks_cronograma_importacao_id_foreign`)
  estourava os 64 chars do MySQL — corrigido com nomes explícitos
  curtos na migration. Suíte completa: 961 passed / 6 skipped
  (skips pré-existentes), incluindo `TenantIsolationTest`.
- **Não tocado**: `MsProjectImporter.php`, `ImportadorCronograma.php`,
  `PlanoImportacao`/`TarefaImportada`/`HorasPeriodo`, regras de HH,
  reconciliação por `external_uid`, a transação/rollback de `aplicar()`.
- **Não avançar pra Fase 2 sem validação do usuário** (instrução
  explícita) — próximas fases (Baseline x atual/duração/HH/lógica/
  caminho crítico/folgas, depois Curva S/produtividade/recursos/
  calendários/pesos/forecast, depois Score de verdade/histórico/
  dashboard) dependem de estender o parser do XML pra capturar
  predecessoras, calendários e slack — qualquer mudança em
  `MsProjectImporter.php` nessas fases futuras exige aprovação prévia
  explícita, conforme combinado.

### Validação final da Fase 1 + ajustes pós-validação

- **Validação funcional**: como upload real de arquivo via seletor
  nativo do SO não é automatizável nas ferramentas de browser
  disponíveis, a validação dos 7 cenários centrais (Baseline, Avanço,
  sem/com alertas, abortar, importar mesmo assim, rollback) foi feita
  via `Livewire::test()` disparado de dentro de `php artisan tinker`
  contra o banco de desenvolvimento real — não os testes automatizados
  (mockados/fila sync), o RUNTIME de produção de verdade: fila Redis
  real, worker de fila real, banco real. **Achado de processo**: a
  migration da Fase 1 nunca tinha rodado no banco de dev (só existia
  no banco `testing`, criado automaticamente pelos testes) — corrigido
  rodando `artisan migrate`. Esse é o motivo de sempre rodar
  `artisan migrate` manualmente depois de criar uma migration nova,
  mesmo com a suíte de testes verde.
- **Severidade ajustada** (decisão do usuário, após revisão da tabela
  das 23 regras): `DATE-001`/`DATE-002` (datas reais após a data de
  status) de Crítico pra Alto — "Crítico" fica reservado
  prioritariamente pra inconsistências logicamente impossíveis
  (`DATE-005`/`DATE-006`, datas invertidas) ou de impacto muito grave
  comprovado. `CRIT-001` (nenhuma atividade em caminho crítico) de
  Médio pra Informativo, com o texto reescrito pra não soar como
  acusação — muitas organizações legitimamente não usam o cálculo de
  caminho crítico do MS Project, e essa era a regra com maior risco de
  falso positivo de todo o conjunto (achado da própria revisão).
- **`DATE-007` — nova regra** (0% de conclusão com término real
  informado, severidade Alto — o inverso de `DATE-003`, que já cobria
  100% sem término real): mesmo padrão das outras 23, mesmo cuidado de
  precisão (`percentualConcluido === 0.0`, nunca trata ausência/`null`
  como zero — 2 testes dedicados garantem essa distinção). Some da
  tabela de 24 regras agora.
- **Polling da tela de Avanço corrigido** (achado da validação: essa
  tela dava sucesso falso, sem esperar o Job assíncrono terminar de
  verdade): `⚡relatorio-importar-avanco.blade.php` ganhou o MESMO
  mecanismo de tracking-id + `Cache` + `wire:poll.2s` já usado em
  `⚡cronograma.blade.php` desde sempre (`$importacaoTrackingId`/
  `$statusImportacao`, `verificarStatusImportacao()`, botões
  desabilitados durante o processamento). **Decisão deliberada**: não
  extrair um trait/serviço compartilhado entre as duas telas — o
  padrão já é pequeno e auto-contido, e duplicar aqui segue a mesma
  convenção já estabelecida no projeto (não compartilhar helper pequeno
  via trait) em vez de arriscar tocar o fluxo de Baseline, que já
  funcionava, só pra economizar ~30 linhas. `⚡cronograma.blade.php`
  não foi alterado nesta etapa.
- Testes novos: 3 unitários pra `DATE-007` em `HealthCheckEngineTest`
  (dispara com 0%+término real; NÃO dispara com percentual ausente;
  NÃO dispara sem término real) + `RelatorioImportarAvancoPollingTest`
  (7 testes, espelhando 1:1 os cenários já cobertos em
  `CronogramaImportacaoLivewireTest` pro fluxo de Baseline: fila
  síncrona finaliza sem ficar "processando"; fila assíncrona mostra
  "processando" e desabilita botões; `verificarStatusImportacao()`
  finaliza com sucesso quando o cache marca concluído; mostra erro
  amigável quando o cache marca erro; cancelar bloqueado durante
  processamento; Health Check associado à importação correta após
  conclusão real; falha dentro de `aplicar()` não deixa Health Check
  órfão). Suíte completa: 971 passed / 6 skipped (skips pré-existentes),
  incluindo `TenantIsolationTest`.

## Report — Assistente de criação: bug "Salvar rascunho não funciona"

- **Contexto**: usuário relatou que o botão "Salvar rascunho" (Passo 5 do
  assistente, `⚡relatorio-novo.blade.php`) parecia não fazer nada ao
  clicar. Nenhum dos 27 testes automatizados do wizard falhava — a causa
  raiz só apareceu investigando o Blade e o ciclo de vida real de upload
  de arquivo do Livewire, não a lógica de negócio em si.
- **Causa raiz nº1 (a mais grave — trava ANTES do usuário chegar no botão)**:
  o Passo 4 (Fotos) sempre renderizava `<img src="{{ $foto->temporaryUrl() }}">`
  pra CADA arquivo em `$novasFotos`, sem checagem nenhuma. `temporaryUrl()`
  lança `FileNotPreviewableException` pra qualquer mimetype fora de
  `config('livewire.temporary_file_upload.preview_mimes')` — que NÃO
  inclui `heic` (formato padrão de foto do iPhone, aceito pelo próprio
  atributo `accept="image/*"` do input em várias combinações de
  navegador/SO). Um usuário anexando uma foto de celular no formato
  nativo quebrava o render do Passo 4 inteiro antes mesmo de conseguir
  avançar pro Passo 5. Corrigido com `@if($foto->isPreviewable()) <img
  ...> @else <ícone + nome do arquivo> @endif` — nunca aceita o arquivo
  por baixo dos panos (a validação de mimes em `salvar()` continua
  rejeitando-o do jeito de sempre), só evita o crash da prévia.
- **Causa raiz nº2**: `salvar()` chama `$this->validate(['periodoReferencia'
  => ..., 'novasFotos.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120'])` —
  mas o usuário está sempre no Passo 5 quando clica em "Salvar rascunho",
  e os únicos blocos `@error` pra essas duas chaves vivem no Passo 1 e no
  Passo 4 respectivamente. Uma `ValidationException` interrompe o método e
  o Livewire re-renderiza o Passo 5 sem NENHUM indício visual do erro —
  o clique parecia literalmente não fazer nada. Corrigido envolvendo a
  chamada num `try/catch(ValidationException)` que redireciona
  `$this->etapa` pro passo onde o erro já tem UI própria (`'1'` ou `'4'`)
  antes de deixar a exceção subir do jeito normal (o `$errors` continua
  populado pelo Livewire exatamente como sempre foi).
- **Rede de segurança adicionada**: `⚡relatorio-novo.blade.php` nunca
  tinha o trait `App\Support\Concerns\ExecutaComTransacaoSegura`, ao
  contrário das 5 páginas centrais do Radar (`⚡restricoes`/`⚡lookahead`/
  `⚡linhas-base`/`⚡plano-semanal`/`⚡curvas`) — qualquer falha inesperada
  em `salvar()` (ex.: erro de storage ao gravar uma foto) desfazia
  silenciosamente, sem toast, sem indicação nenhuma. Agora o corpo de
  `salvar()` (criação/reaproveitamento do Report, pontos de atenção,
  fotos) roda dentro de `$this->transacaoSegura(fn () => ..., 'mensagem')`
  — autorização/validação continuam subindo normalmente (tratadas antes),
  só falhas de verdade caem no toast de erro do trait.
- **Nada do ciclo de vida do Report foi tocado**: `avancar()`,
  `prepararRascunhoDoDiagnostico()`, `hydrate()`, `#[Computed] diagnostico()`,
  `DiagnosticoReport`, `ReportGerador`, `ImpactoRestricoesGerador` — zero
  linha alterada. Os dois caminhos de `salvar()` (Report já existe vs.
  fallback direto) continuam exatamente como estavam, só agora dentro do
  wrapper seguro.
- Testes novos em `tests/Feature/ReportWizardDiagnosticoTest.php`:
  `test_salvar_com_foto_invalida_no_passo_5_volta_pro_passo_4_com_erro_visivel`
  (o erro fica visível, o usuário nunca fica preso no Passo 5 sem
  feedback, nada é perdido — mesmo Report, sem redirecionamento) e
  `test_salvar_com_foto_valida_apos_corrigir_o_erro_funciona_normalmente`
  (corrige a foto e salva de novo, sem sair do zero). Suíte completa do
  wizard: 27/27. Suíte completa do projeto: sem regressão (as 2 falhas
  pré-existentes de fixture com data relativa — `DocumentosEngenhariaDashboardTest`/
  `ItemSuprimentoStatusTest` — continuam as mesmas, sem relação com esta
  correção). `TenantIsolationTest`: 20/20.
- **Achado paralelo, ambiente (não código, corrigido separadamente)**: o
  cache de descoberta de pacotes do container Sail (`bootstrap/cache/
  packages.php`) estava desatualizado desde antes da Fase 1 do roadmap de
  maturidade SaaS (12/07), nunca regenerado depois de `sentry/sentry-laravel`
  ser adicionado ao `composer.json` (20/07) — fazendo o canal de log
  padrão (`stack` → `sentry`) quebrar com `Driver [sentry] is not
  supported` sempre que QUALQUER exceção precisasse ser logada, em
  qualquer parte do sistema. Corrigido rodando `php artisan package:discover`
  + `php artisan clear-compiled` no container — nenhum arquivo
  versionado alterado (`bootstrap/cache/*` está no `.gitignore`). Isso
  por si só não era a causa raiz do botão "Salvar rascunho" (os dois bugs
  reais estão documentados acima), mas era um problema de ambiente real e
  independente, que mascarava mensagens de erro sempre que uma exceção de
  verdade acontecia — vale rodar esse par de comandos sempre que um pacote
  novo for adicionado ao `composer.json` e o container não for recriado
  do zero.

## Preservação de pendências operacionais na importação (Ciclo 17, A.9)

- **Regra de produto definitiva**: o cronograma importado é a verdade
  factual; a plataforma preserva o histórico/pendências operacionais; a
  importação nunca corrige silenciosamente Restrição ou Prontidão.
- **A.9.1 — remoção da autocorreção automática**: `App\Support\
  ConclusaoAutomaticaAtividades` (resolve restrições abertas + marca itens
  de prontidão concluídos, só porque `percentual_concluido >= 100`) deixou
  de ser chamada automaticamente por `MsProjectImporter::aplicar()` e por
  `BackfillLookaheadCommand`. A classe continua existindo, funcionando
  normalmente e coberta por teste (`tests/Unit/
  ConclusaoAutomaticaAtividadesTest.php`) — mas só para saneamento manual
  explícito (`tinker`), nunca no fluxo automático. Docblock da classe
  documenta isso e aponta pra detecção futura (nunca correção silenciosa).
  Confirmado por auditoria adversarial dedicada (fresh code read + 10
  suítes de regressão + reprodução empírica via fluxo real de importação):
  Restrição aberta/resolvida e item de prontidão pendente/concluído
  permanecem bit-a-bit intocados mesmo quando a atividade chega a 100%,
  inclusive em reimportação 100%→100% e em primeira importação de Avanço
  já nascendo em 100%; zero `RestricaoAcao` automática criada durante
  import (único outro criador de `RestricaoAcao` no projeto,
  `App\Support\SincronizarRestricaoSuprimento`, é mecanismo pré-existente
  e ortogonal — reage a risco de prazo de item de suprimento, nunca a
  `percentual_concluido`). `AtividadeObserver` só reage a `isDirty('status')`
  — a importação nunca escreve `status`, então `percentual_concluido`
  chegar a 100 nunca dispara `concluido_em`/mudança de `Atividade.status`
  (isso já era assim antes da A.9.1, não é uma mudança de comportamento
  desta fase; o único acoplamento removido foi `percentual_concluido`
  → `Restricao`/`AtividadeItemProntidao`).
- **A.9.2 — Fotografia F (preservação factual por importação)**:
  `atividade_snapshots` ganhou 3 colunas aditivas —
  `percentual_concluido` (`decimal(5,2)` nullable, mesmo tipo de
  `atividades.percentual_concluido`), `real_inicio`/`real_termino`
  (`date` nullable, mesmo tipo de `atividades.real_inicio`/
  `real_termino`) — migration
  `2026_08_17_000001_add_fotografia_factual_to_atividade_snapshots_table.php`.
  Objetivo: responder no futuro "o que o cronograma declarou NESTA
  importação" sem depender do estado ao vivo da `Atividade` (que só
  guarda o valor mais recente). **Fonte dos 3 campos novos é
  `TarefaImportada` (a DTO desta própria iteração do loop), nunca `$at`
  relido após o upsert** — decisão deliberada, documentada em comentário
  em `MsProjectImporter::aplicar()`: queremos a fotografia do que o
  ARQUIVO declarou, não um efeito colateral acidental do model ao vivo
  (mesmo quando os dois numericamente coincidem nesta versão do código).
  `TarefaImportada::$percentualConcluido`/`$realInicio`/`$realTermino`
  são populados pelo parser (`PercentWorkComplete`/`ActualStart`/
  `ActualFinish`) de forma **uniforme, independente de `tipo`** — por
  isso uma importação Baseline pura que traga esses campos no XML também
  os registra na Fotografia F (é só um FATO preservado; **não** torna a
  importação elegível como Tendência/Avanço na UI, que continua
  exclusivamente controlada por `CronogramaImportacao.tipo IN (Avanco,
  Ambos)` via `CurvaAvanco::resolverImportacaoId()`, não tocado nesta
  fase). Importação `Ambos` grava normalmente no mesmo snapshot único —
  nunca dois snapshots (Baseline + Avanço) para a mesma importação.
- **Null vs zero preservado com precisão**: `PercentWorkComplete`
  ausente do XML → `null` (informação desconhecida); `<PercentWorkComplete>
  0</PercentWorkComplete>` explícito → `0.0` (fato: "declarado como 0%"),
  nunca vira `null`. Mesma distinção para `ActualStart`/`ActualFinish`
  ausentes → `null`, nunca uma data inventada.
- **Histórico nunca é sobrescrito**: cada `CronogramaImportacao` grava seu
  próprio snapshot por atividade (`unique(cronograma_importacao_id,
  atividade_id)`, já existente desde a criação da tabela) — uma sequência
  de importações 40%→70%→100% da MESMA atividade deixa TRÊS fotografias
  distintas e consultáveis independentemente
  (`AtividadeSnapshot::where('cronograma_importacao_id', $id)...`), nunca
  só "a mais recente". A `Atividade` ao vivo reflete só o estado atual;
  os snapshots são o único jeito de reconstruir "o que era verdade na
  importação X".
- **Backfill nunca inventa fato histórico**: `BackfillLookaheadCommand::
  criarSnapshotDaUltimaImportacao()` continua criando snapshot retroativo
  só com os 4 campos que já tinha (`inicio_planejado`/`data_termino`/
  `baseline_inicio`/`baseline_termino`, lidos da Atividade viva) — **NÃO**
  foi alterado para também copiar `percentual_concluido`/`real_inicio`/
  `real_termino` do estado atual pra uma importação passada, porque isso
  seria inventar um fato histórico que ninguém registrou de verdade
  naquele momento. Snapshots criados pelo Backfill ficam com os 3 campos
  novos em `NULL`, mesmo quando a Atividade viva já está 100% com datas
  reais preenchidas — comportamento coberto por teste dedicado
  (`test_backfill_nao_inventa_percentual_e_datas_reais_historicas_a_partir_da_atividade_viva`).
  Regra geral: **Fotografia F só é confiável para importações processadas
  DEPOIS da A.9.2; snapshots legados (criados antes desta migration, ou
  criados pelo Backfill sem fonte confiável) permanecem `null` nos 3
  campos novos** — nunca reconstruídos a partir do estado atual.
- **Zero acoplamento com estado operacional**: Fotografia F nunca contém
  Restrição, contagem de restrições, prontidão, comentário, anexo, causa,
  `Atividade.status` ou `concluido_em` — só os 3 fatos brutos do
  cronograma. Confirmado que salvar uma Fotografia F a 100% não
  reintroduz nenhum comportamento da A.9.1 (mesmo teste de regressão:
  Restrição/prontidão continuam intactas, zero `RestricaoAcao`
  automática).
- **Não implementado nesta fase** (fora de escopo, aguardando fase
  futura): `InconsistenciaAvanco`/detector de inconsistências, Fotografia
  O (estado operacional versionado), Job de análise, Notification,
  Dashboard, UI — Fotografia F é só a camada de persistência histórica;
  nada consome esses 3 campos novos ainda em nenhuma tela.
- Testes: `tests/Feature/AtividadeSnapshotFotografiaFTest.php` (9 testes
  — percentual parcial, 100% com as duas datas reais, três importações
  sucessivas preservando três fotografias distintas, Baseline pura
  registra o fato, importação Ambos, zero explícito permanece zero,
  ausência de dado permanece null, Fotografia F não reintroduz
  autocorreção da A.9.1, snapshot sem os campos novos aceita null) + 1
  teste novo em `BackfillLookaheadCommandTest.php` (Backfill não inventa
  histórico) + 1 teste novo em `TenantIsolationTest.php` (`AtividadeSnapshot`
  escopado por tenant). Fixtures novas: `cronograma_fase_a92_70pct.xml`,
  `cronograma_fase_a92_zero_e_termino.xml`. Suíte completa sem regressão:
  297 passed / 922 assertions / 0 failures, incluindo `TenantIsolationTest`
  e toda a suíte de A.9.1/Health Check/Plano de Ação/Suprimentos.

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

## Suporte e Feedback (fase de testes)

- **Canal de feedback interno** — tabela `feedbacks` (ULID, `tenant_id`
  cascade, `user_id` restrict — mesma política de FK de autoria por
  LGPD de sempre), enum `App\Enums\TipoFeedback` (`erro`|`melhoria`|
  `critica`, com `label()`). `App\Models\Feedback` declara `tenant()` e
  `user()` explicitamente — `BelongsToTenant` só dá o scope + auto-stamp
  de `tenant_id`, nunca a relação em si.
- **Dois pontos de entrada, um único popup**: o link "Suporte" no
  rodapé (substituiu o link externo antigo do tema, `config('variables
  .support')` ficou órfão de propósito) e o alerta "Sistema em testes"
  na navbar (irmão do bloco do seletor de Empresa Ativa, fora do
  `@if(!isset($hideEmpresaSwitcher))` — por isso aparece também em
  `/admin`) chamam o MESMO componente Livewire persistido
  (`resources/views/components/suporte/⚡popup.blade.php`,
  `@persist('suporte-popup')` em `contentNavbarLayout.blade.php` e
  `layoutAdmin.blade.php`) via `Livewire.dispatch('abrir-suporte',
  { url: window.location.href })` — nenhum dos dois pontos precisa
  estar dentro da árvore do componente.
- **Achado desta fase — `$wire.on()` não recebe `Livewire.dispatch()`
  disparado de FORA de qualquer componente**: só um listener `Livewire
  .on()` (global, não scoped) recebe esse tipo de dispatch "anônimo".
  `$wire.on()` (dentro do `@script` do próprio componente) só recebe
  eventos disparados PARA aquele componente especificamente — o que já
  funciona hoje para `fechar-modal-suporte`/`show-toast`, disparados
  pelo próprio backend via `$this->dispatch()` dentro de `enviar()`.
  Confirmado inspecionando o bundle vendorizado do Livewire (`effects
  .scripts` só é processado uma vez, na hidratação inicial via
  `processEffects()` no construtor do componente — depois disso, um
  dispatch global de fora não acorda um `$wire.on()`, só um `Livewire
  .on()` de verdade). Regra geral pro projeto: abrir um componente
  persistido a partir de FORA da sua árvore (footer, navbar, qualquer
  `onclick` solto) precisa de `Livewire.on(...)`, nunca `$wire.on(...)`.
- **Dados capturados automaticamente** (usuário nunca preenche à mão):
  tenant via `TenantContext::currentId()`, usuário via `Auth::id()`,
  URL de origem capturada no clique (`window.location.href`) e enviada
  pro backend via `abrir(string $url)` (chamado no mesmo listener que
  abre o modal), timestamp via `created_at` de sempre.
- **Dois e-mails, dois propósitos**: `NovoFeedbackNotification` pra
  `contato@dcf.eng.br` (fixo, via `Notification::route('mail', ...)`,
  mesmo padrão de `ConviteObraNotification`) com tipo/mensagem/usuário/
  tenant/data/URL — informação técnica que só a equipe vê;
  `AgradecimentoFeedbackNotification` pro próprio usuário (`Auth::user()
  ->notify(...)`), tom amigável, sem prometer prazo/implementação, sem
  nenhum dado técnico interno.
- **Rate limit** (`RateLimiter`, chave `"enviar-feedback:".Auth::id()`,
  5 tentativas/300s, mesmo padrão de `enviar-convite`) + validação via
  `rules()` (`Rule::in()` sobre os valores do enum — sem precedente de
  `Rule::enum()` no projeto, mantido o estilo já usado em todo lugar) +
  `ExecutaComTransacaoSegura` (falha preserva a mensagem digitada, nunca
  reseta o formulário nem confirma sucesso; toast de erro já é
  automático do trait).
- **Sem histórico/status/gamificação nesta fase** (decisão do usuário,
  registrada pra não reinventar depois): a tabela já grava tudo que uma
  evolução futura precisaria (tenant/usuário/tipo/mensagem/data), mas
  não existe tela de acompanhamento, pontuação ou ranking — só o canal
  de envio em si.
- `feedbacks` entra em `App\Actions\ExportUserData::gerar()` (regra já
  documentada acima pra toda tabela nova com FK de autoria pra `users`).

## Como trabalhar neste repositório

- O projeto já está em andamento: **audite antes de alterar** e adeque o
  que existe ao alvo, em vez de recriar do zero.
- Para mudanças de schema, proponha um plano em Plan Mode e espere
  aprovação explícita. Nunca edite migrations já aplicadas em produção.
- Forma-alvo de referência em `/referencia/dcf-radar`.
- Decisões e roadmap completos em `docs/BRIEFING-DCF-RADAR.md`.
