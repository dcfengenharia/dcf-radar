<?php

use App\DTOs\Gestao\Home\HomeExecutiva;
use App\Models\Work;
use App\Support\Gestao\HomeExecutivaQuery;
use App\Support\Gestao\UltimoAcessoHomeTracker;
use App\Support\ObraContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Home Executiva (Ciclo 25) — página inicial da plataforma (`app.home`).
 * Substitui o placeholder do template ("On route vehicles"/curso) por
 * uma síntese executiva orientada à ação, sempre derivada de
 * `App\Support\Gestao\HomeExecutivaQuery` (nunca calcula regra de
 * negócio própria).
 *
 * **Fora de `obra.context` de propósito** (Seção 3 do pedido — mesma
 * decisão de risco mínimo já usada em `⚡licoes-aprendidas.blade.php`/
 * `⚡benchmarking-obras.blade.php`, ESCOPO_TENANT): a rota `app.home` já
 * é o destino de login padrão de todo usuário (`RouteServiceProvider::
 * HOME`), inclusive de tenants recém-criados sem nenhuma obra ainda —
 * colocá-la dentro de `obra.context` forçaria uma seleção de obra ANTES
 * mesmo do usuário ver a própria Home, mudando um fluxo crítico de
 * login pra todo o sistema. Em vez disso, esta página resolve a obra
 * ativa sozinha, com seletor próprio (mesmo idioma de
 * `⚡dashboard.blade.php::trocarObra()`).
 */
new class extends Component {
    /**
     * Cursor de "última vez que este usuário abriu a Home desta obra",
     * capturado ANTES de ser avançado pra agora — só em `mount()`
     * (navegação real, nunca re-render interno do Livewire). `null` =
     * primeira vez que este usuário abre a Home desta obra.
     */
    public ?string $ultimoAcessoAnteriorEm = null;

    public function mount(): void
    {
        if (! ObraContext::current()) {
            $obras = Auth::user()->works()->orderBy('name')->limit(2)->get();
            if ($obras->count() === 1) {
                ObraContext::set($obras->first());
            }
        } elseif (! Auth::user()->temAcessoAObra(ObraContext::current())) {
            ObraContext::clear();
        }

        if ($obra = ObraContext::current()) {
            $anterior = UltimoAcessoHomeTracker::capturarEAvancar($obra, Auth::user());
            $this->ultimoAcessoAnteriorEm = $anterior?->toIso8601String();
        }
    }

    #[Computed]
    public function obraAtual(): ?Work
    {
        return ObraContext::current();
    }

    #[Computed]
    public function obrasDoUsuario()
    {
        return Auth::user()->works()->orderBy('name')->get(['works.id', 'works.name']);
    }

    #[Computed]
    public function resumo(): ?HomeExecutiva
    {
        $obra = $this->obraAtual;
        $cursor = $this->ultimoAcessoAnteriorEm ? Carbon::parse($this->ultimoAcessoAnteriorEm) : null;

        return $obra ? HomeExecutivaQuery::resumo($obra, $cursor) : null;
    }

    /**
     * Fechamento (Seções 24-26) — o resumo executivo nunca vira bypass de
     * autorização: quem não tem `ver` em Engenharia/Suprimentos (rara na
     * prática, já que `ver` é liberado por padrão a todo perfil vinculado
     * à obra, mas possível) não vê nome/código de documento nem de
     * material dentro da Home, só um aviso — os totais/contagens
     * agregadas continuam visíveis (nunca um recurso individual vedado).
     */
    #[Computed]
    public function podeVerEngenharia(): bool
    {
        return Auth::user()->temPermissaoEmAlgumaObraDoTenant('engenharia.pacotes', 'ver');
    }

    #[Computed]
    public function podeVerSuprimentos(): bool
    {
        return $this->obraAtual && Auth::user()->temPermissaoNaObra($this->obraAtual, 'suprimentos.mapa', 'ver');
    }

    #[Computed]
    public function podeVerEstoque(): bool
    {
        return $this->obraAtual && Auth::user()->temPermissaoNaObra($this->obraAtual, 'estoque.reserva', 'ver');
    }

    /** Ações recomendadas visíveis nesta obra, filtradas pela permissão de quem está vendo (Seção 25 — resumo nunca vira bypass). */
    #[Computed]
    public function acoesRecomendadasVisiveis(): array
    {
        $acoes = $this->resumo?->acoesRecomendadas ?? [];

        return array_values(array_filter($acoes, function (array $acao) {
            if ($acao['tipo'] === 'reservar') {
                return $this->podeVerEstoque;
            }
            if ($acao['tipo'] === 'documento') {
                return $this->podeVerEngenharia;
            }

            return true;
        }));
    }

    /**
     * Ameaças com a CAUSA redigida quando o domínio dela é vedado ao
     * usuário atual (Seção 25) — nunca esconde a atividade em si (ela já
     * é visível via Lookahead/Restrições, permissões amplamente
     * concedidas por padrão), só o detalhe específico (código de
     * documento/material) que pertence a um módulo com `ver` restrito.
     */
    #[Computed]
    public function ameacasVisiveis(): array
    {
        $ameacas = $this->resumo?->ameacas ?? [];

        return array_map(function (array $ameaca) {
            if ($ameaca['categoria'] === 'engenharia' && ! $this->podeVerEngenharia) {
                $ameaca['causa'] = 'Documento de Engenharia não liberado (detalhe restrito por permissão)';
            }
            if ($ameaca['categoria'] === 'suprimentos' && ! $this->podeVerSuprimentos) {
                $ameaca['causa'] = 'Exposição de Suprimentos (detalhe restrito por permissão)';
            }

            return $ameaca;
        }, $ameacas);
    }

    /** Mesmo cuidado do `ameacasVisiveis` aplicado a "O que mudou" — nunca vazar código de documento de quem não tem `ver` em Engenharia. */
    #[Computed]
    public function ultimosAcontecimentosVisiveis(): array
    {
        $eventos = $this->resumo?->ultimosAcontecimentos ?? [];

        if ($this->podeVerEngenharia) {
            return $eventos;
        }

        return array_map(function (array $evento) {
            if ($evento['tipo'] === 'documento_liberado') {
                $evento['descricao'] = 'Um documento de Engenharia foi liberado para construção (detalhe restrito por permissão)';
            }

            return $evento;
        }, $eventos);
    }

    /**
     * Trocar de obra na própria Home também troca o contexto da SESSÃO
     * inteira (mesmo mecanismo de `⚡dashboard.blade.php::trocarObra()`)
     * — nunca um filtro local desta página, pra o navbar/menu nunca
     * divergir do que a Home mostra.
     */
    public function trocarObra(string $obraId): void
    {
        $obra = Work::findOrFail($obraId);

        abort_unless(Auth::user()->temAcessoAObra($obra), 403);

        ObraContext::set($obra);

        $this->redirect(route('app.home'));
    }
};
?>

<div>
    <div class="row mb-4">
        <div class="col-12 col-lg-8">
            {{-- Saudação original preservada (Seção 2 do pedido: "MANTER a
                 saudação atual... não substituir a identidade existente") --}}
            <h3 class="mb-1">Fala comigo {{ Auth::user()->first_name }} 👋🏻</h3>
            <p class="text-muted mb-0">
                @if ($this->obraAtual)
                    Aqui está o que precisa da sua atenção em <strong>{{ $this->obraAtual->name }}</strong> hoje.
                @else
                    Selecione uma obra para ver o que precisa da sua atenção hoje.
                @endif
            </p>
        </div>
        @if ($this->obrasDoUsuario->count() > 1)
            <div class="col-12 col-lg-4 mt-3 mt-lg-0 text-lg-end">
                <label class="form-label small mb-0 me-2">Obra</label>
                <select class="form-select form-select-sm d-inline-block w-auto"
                        wire:change="trocarObra($event.target.value)">
                    <option value="">Selecione…</option>
                    @foreach ($this->obrasDoUsuario as $obra)
                        <option value="{{ $obra->id }}" @selected($this->obraAtual?->id === $obra->id)>{{ $obra->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    @if (! $this->obraAtual)
        {{-- Seção 36 / Seção 41 — sem obra selecionada, sem regra de negócio nenhuma pra vazar. --}}
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bx bx-buildings bx-lg text-muted mb-3"></i>
                <h5>Nenhuma obra selecionada</h5>
                <p class="text-muted">
                    @if ($this->obrasDoUsuario->isEmpty())
                        Você ainda não tem acesso a nenhuma obra.
                    @else
                        Escolha uma obra acima, ou na tela "Minhas Obras", para ver a síntese executiva.
                    @endif
                </p>
                <a href="{{ route('gestao.minhas-obras') }}" class="btn btn-primary">
                    <i class="bx bx-transfer me-1"></i>Minhas Obras
                </a>
            </div>
        </div>
    @elseif (! $this->resumo->temCronograma)
        {{-- Seção 36 — obra existe, mas sem cronograma importado ainda. --}}
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bx bx-calendar-event bx-lg text-muted mb-3"></i>
                <h5>Esta obra ainda não tem atividades cadastradas</h5>
                <p class="text-muted">{{ $this->resumo->fraseGerencial }}</p>
                <a href="{{ route('radar.cronograma') }}" class="btn btn-primary">
                    <i class="bx bx-upload me-1"></i>Importar Cronograma
                </a>
            </div>
        </div>
    @else
        @php($r = $this->resumo)

        {{-- ============================================================
             HERO — Prontidão, próximas 2 semanas
        ============================================================= --}}
        <div class="card mb-4 {{ $r->heroProntidao->bloqueadas > 0 ? 'border-danger' : 'border-success' }}">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-12 col-md-3 text-center border-end-md">
                        <div class="text-muted small text-uppercase mb-1">Prontidão — {{ $r->heroProntidao->label }}</div>
                        <h1 class="mb-0 {{ ($r->heroProntidao->percentual ?? 0) >= 80 ? 'text-success' : (($r->heroProntidao->percentual ?? 0) >= 50 ? 'text-warning' : 'text-danger') }}">
                            {{ $r->heroProntidao->percentual !== null ? number_format($r->heroProntidao->percentual, 0) . '%' : '—' }}
                        </h1>
                    </div>
                    <div class="col-12 col-md-4 text-center my-3 my-md-0">
                        <div class="d-flex justify-content-center gap-4">
                            <div>
                                <h4 class="mb-0 text-success">{{ $r->heroProntidao->prontas }}</h4>
                                <small class="text-muted">prontas</small>
                            </div>
                            <div>
                                <h4 class="mb-0 text-danger">{{ $r->heroProntidao->bloqueadas }}</h4>
                                <small class="text-muted">bloqueadas</small>
                            </div>
                            @if ($r->heroProntidao->concluidas > 0)
                                <div>
                                    <h4 class="mb-0 text-primary">{{ $r->heroProntidao->concluidas }}</h4>
                                    <small class="text-muted">concluídas</small>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="col-12 col-md-5">
                        <p class="mb-0 fs-6">{{ $r->fraseGerencial }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            {{-- ============================================================
                 O QUE AMEAÇA A EXECUÇÃO
            ============================================================= --}}
            <div class="col-12 col-lg-7 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5 class="mb-0"><i class="bx bx-error-circle text-danger me-1"></i>O que ameaça a execução</h5>
                    </div>
                    <div class="card-body">
                        @if ($this->ameacasVisiveis === [])
                            <p class="text-success mb-0"><i class="bx bx-check-circle me-1"></i>Nenhuma atividade crítica das próximas 2 semanas exige ação agora.</p>
                        @else
                            <div class="list-group list-group-flush">
                                @foreach ($this->ameacasVisiveis as $ameaca)
                                    <a href="{{ route($ameaca['deep_link']['rota'], $ameaca['deep_link']['parametros']) }}"
                                       class="list-group-item list-group-item-action px-0">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <span class="badge {{ $ameaca['severidade'] === 'bloqueante' ? 'bg-danger' : ($ameaca['severidade'] === 'atencao' ? 'bg-warning' : 'bg-label-info') }} me-2">
                                                    {{ $ameaca['severidade'] === 'bloqueante' ? 'Bloqueio' : ($ameaca['severidade'] === 'atencao' ? 'Atenção' : 'Risco') }}
                                                </span>
                                                <strong>{{ $ameaca['nome'] }}</strong>
                                                <div class="small text-muted">{{ $ameaca['causa'] }}</div>
                                            </div>
                                            <div class="text-end small text-muted text-nowrap ms-2">
                                                @if ($ameaca['dias_para_inicio'] < 0)
                                                    <span class="text-danger">atrasada há {{ abs($ameaca['dias_para_inicio']) }}d</span>
                                                @else
                                                    início em {{ $ameaca['dias_para_inicio'] }}d
                                                @endif
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 PRONTIDÃO NO HORIZONTE
            ============================================================= --}}
            <div class="col-12 col-lg-5 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">Prontidão no horizonte</h5></div>
                    <div class="card-body">
                        <div class="row g-3 text-center">
                            @foreach ($r->horizontes as $h)
                                <div class="col-6">
                                    <div class="border rounded p-2 h-100">
                                        <div class="small text-muted">{{ $h->label }}</div>
                                        <div class="fw-bold {{ ($h->percentual ?? 0) >= 80 ? 'text-success' : (($h->percentual ?? 0) >= 50 ? 'text-warning' : 'text-danger') }}">
                                            {{ $h->percentual !== null ? number_format($h->percentual, 0) . '%' : '—' }}
                                        </div>
                                        <div class="small text-muted">{{ $h->prontas }}/{{ $h->total - $h->concluidas }} prontas</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            {{-- ============================================================
                 CAUSAS — POR QUE NÃO ESTAMOS PRONTOS
            ============================================================= --}}
            <div class="col-12 col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">Por que não estamos prontos?</h5></div>
                    <div class="card-body">
                        @if ($r->causas === [])
                            <p class="text-success mb-0">Nenhuma causa de bloqueio identificada nas próximas 2 semanas.</p>
                        @else
                            @foreach ($r->causas as $causa)
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between">
                                        <span>{{ $causa['label'] }}</span>
                                        <strong>{{ $causa['quantidade_atividades'] }} atividade(s)</strong>
                                    </div>
                                    <div class="progress" style="height:6px">
                                        <div class="progress-bar bg-danger" style="width: {{ $r->heroProntidao->total > 0 ? min(100, $causa['quantidade_atividades'] / $r->heroProntidao->total * 100) : 0 }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 SUPRIMENTOS × EXECUÇÃO (Motor V1)
            ============================================================= --}}
            <div class="col-12 col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">Suprimentos × Execução</h5></div>
                    <div class="card-body">
                        <p class="small mb-2">{{ $r->suprimentos['frase'] }}</p>
                        @if (! $this->podeVerSuprimentos)
                            <p class="text-muted small mb-0"><i class="bx bx-lock-alt me-1"></i>Você não tem permissão para ver o detalhamento de Suprimentos desta obra.</p>
                        @elseif ($r->suprimentos['total_necessidades'] > 0)
                            <ul class="list-unstyled small mb-0">
                                <li>🔴 Sem cobertura comercial: <strong>{{ $r->suprimentos['sem_cobertura_comercial'] }}</strong></li>
                                <li>🔴 Pedido sem prazo: <strong>{{ $r->suprimentos['pedido_sem_prazo'] }}</strong></li>
                                <li>🔴 Entrega prevista após a necessidade: <strong>{{ $r->suprimentos['entrega_posterior_necessidade'] }}</strong></li>
                                <li>🟡 Recebido, aguardando disponibilização: <strong>{{ $r->suprimentos['recebida_aguardando'] }}</strong></li>
                                <li>🟢 Disponível, ainda sem reserva: <strong>{{ $r->suprimentos['disponivel_nao_reservada'] }}</strong></li>
                                <li>🟢 Protegida / dependente no prazo: <strong>{{ $r->suprimentos['protegida'] + $r->suprimentos['dependente_no_prazo'] }}</strong></li>
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            {{-- ============================================================
                 ENGENHARIA × EXECUÇÃO
            ============================================================= --}}
            <div class="col-12 col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">Engenharia × Execução</h5></div>
                    <div class="card-body">
                        <p class="small mb-2">{{ $r->engenharia['frase'] }}</p>
                        @if (! $this->podeVerEngenharia)
                            @if ($r->engenharia['total_atividades'] > 0)
                                <p class="text-muted small mb-0"><i class="bx bx-lock-alt me-1"></i>Você não tem permissão para ver o detalhamento de Engenharia.</p>
                            @endif
                        @elseif ($r->engenharia['documentos'] !== [])
                            <ul class="list-unstyled small mb-0">
                                @foreach ($r->engenharia['documentos'] as $doc)
                                    <li>
                                        <a href="{{ route('engenharia.pacotes', ['documento' => $doc['documento_id']]) }}">{{ $doc['codigo'] }}</a>
                                        — {{ $doc['atividades'] }} atividade(s)
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 AÇÕES QUE MAIS PROTEGEM O PLANO
            ============================================================= --}}
            <div class="col-12 col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">Ações que mais protegem o plano agora</h5></div>
                    <div class="card-body">
                        @if ($this->acoesRecomendadasVisiveis === [])
                            <p class="text-muted mb-0">Nenhuma ação prioritária identificada agora.</p>
                        @else
                            <div class="list-group list-group-flush">
                                @foreach ($this->acoesRecomendadasVisiveis as $acao)
                                    <a href="{{ route($acao['deep_link']['rota'], $acao['deep_link']['parametros']) }}" class="list-group-item list-group-item-action px-0">
                                        <div class="d-flex justify-content-between">
                                            <strong>{{ $acao['titulo'] }}</strong>
                                            <span class="badge bg-label-success">{{ $acao['impacto'] }}</span>
                                        </div>
                                        <div class="small text-muted">{{ $acao['descricao'] }}</div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            {{-- ============================================================
                 ÚLTIMOS ACONTECIMENTOS
            ============================================================= --}}
            <div class="col-12 col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">
                            {{ $r->primeiroAcessoHome ? 'Últimos acontecimentos da obra' : 'O que mudou desde sua última visita' }}
                        </h5>
                    </div>
                    <div class="card-body">
                        @if ($this->ultimosAcontecimentosVisiveis === [])
                            <p class="text-muted mb-0">
                                {{ $r->primeiroAcessoHome ? 'Nenhum acontecimento registrado nos últimos 7 dias.' : 'Nenhuma mudança relevante desde sua última visita.' }}
                            </p>
                        @else
                            <ul class="list-unstyled small mb-0">
                                @foreach ($this->ultimosAcontecimentosVisiveis as $evento)
                                    <li class="mb-2">
                                        <i class="bx {{ match($evento['tipo']) {
                                            'restricao_resolvida' => 'bx-check-circle text-success',
                                            'documento_liberado' => 'bx-file text-info',
                                            'pedido_previsao_revisada' => 'bx-calendar-edit text-warning',
                                            default => 'bx-package text-primary',
                                        } }} me-1"></i>
                                        {{ $evento['descricao'] }}
                                        <span class="text-muted">— {{ \Illuminate\Support\Carbon::parse($evento['quando'])->diffForHumans() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 EXECUÇÃO DA SEMANA / PPC
            ============================================================= --}}
            <div class="col-12 col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">Execução da semana</h5></div>
                    <div class="card-body">
                        @if (! $r->execucaoSemana)
                            <p class="text-muted mb-0">Nenhuma Programação Semanal fechada ainda para calcular o PPC.</p>
                        @else
                            @php($e = $r->execucaoSemana)
                            <div class="d-flex align-items-center gap-4 mb-2">
                                <div>
                                    <h3 class="mb-0 {{ $e['ppc_percentual'] >= 80 ? 'text-success' : ($e['ppc_percentual'] >= 60 ? 'text-warning' : 'text-danger') }}">
                                        {{ number_format($e['ppc_percentual'], 0) }}%
                                    </h3>
                                    <small class="text-muted">PPC</small>
                                </div>
                                <div class="small">
                                    <div>{{ $e['comprometidas'] }} comprometidas</div>
                                    <div>{{ $e['concluidas_no_prazo'] }} concluídas no prazo</div>
                                    <div>{{ $e['nao_concluidas'] }} não concluídas</div>
                                </div>
                            </div>
                            <p class="small mb-0">{{ $e['frase'] }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================================
             PRONTIDÃO ATUAL × RECUPERÁVEL
        ============================================================= --}}
        @php($rec = $r->prontidaoRecuperavel)
        <div class="alert {{ $rec['atividades_recuperaveis'] > 0 ? 'alert-info' : 'alert-light border' }} d-flex align-items-center gap-3">
            <i class="bx {{ $rec['atividades_recuperaveis'] > 0 ? 'bx-trending-up' : 'bx-check-shield' }} bx-md"></i>
            <div>
                <strong>
                    Prontidão atual: {{ $rec['percentual_atual'] !== null ? number_format($rec['percentual_atual'], 0) . '%' : '—' }}
                    @if ($rec['atividades_recuperaveis'] > 0)
                        · Potencial comprovável: {{ number_format($rec['percentual_potencial'], 0) }}%
                    @endif
                </strong>
                <div>{{ $rec['frase'] }}</div>
                @if ($rec['atividades_recuperaveis'] > 0)
                    <div class="small text-muted mt-1">
                        {{ $rec['recuperaveis_uma_acao'] }} atividade(s) com 1 ação conhecida ·
                        {{ $rec['recuperaveis_multiplas_acoes'] }} atividade(s) com múltiplas ações conhecidas
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
