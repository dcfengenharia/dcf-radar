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
