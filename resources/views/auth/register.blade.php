@php
$configData = Helper::appClasses();
$customizerHidden = 'customizer-hide';

$steps = ['acesso' => 'Acesso', 'empresa' => 'Empresa'];
if ($planosAtivos->isNotEmpty()) {
    $steps['plano'] = 'Plano';
}
$steps['revisao'] = 'Revisão';
@endphp

@extends('layouts.blankLayout')

@section('title', 'Novo Cadastro')

@section('page-style')
<!-- Page -->
<link rel="stylesheet" href="{{asset('assets/vendor/css/pages/page-auth.css')}}">
<link rel="stylesheet" href="{{ asset('assets/css/register_dcf.css') }}">
@endsection

@section('content')
<div class="authentication-wrapper authentication-cover">
  <div class="authentication-inner row m-0">

    <!-- /Left Text -->
    <div class="d-none d-lg-flex col-lg-7 col-xl-8 align-items-center d-none d-lg-flex auth-left-side">
      <div class="auth-overlay"></div>
      <div class="w-100 px-5 py-5 text-white position-relative z-2">

            <!-- HEADLINE -->
            <div class="mb-4" style="max-width: 620px;">

                <h1 class="display-4 fw-bold text-white lh-1 mb-4">
                    O quadro de restrições que antecipa
                    <span class="text-warning">
                        problemas antes da obra parar
                    </span>
                </h1>

                <p class="fs-4 text-white-50">
                    Last Planner System na prática: identifique impedimentos, cobre
                    responsáveis e só leve pro plano semanal o que está pronto.
                </p>

            </div>

            <!-- BENEFÍCIOS -->
            <div class="row mt-5">

                <div class="col-12 col-md-6">

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-line-chart"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Indicadores de previsibilidade
                            </h5>

                            <p class="text-white-50 mb-0">
                                PPC histórico e curvas S por obra, sempre atualizados.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-bell-plus"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Alertas inteligentes
                            </h5>

                            <p class="text-white-50 mb-0">
                                Prazos de suprimento avisados 21 e 10 dias antes de vencer.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-trending-up"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Tendência de prazo
                            </h5>

                            <p class="text-white-50 mb-0">
                                Compare linha de base e realizado pra antecipar desvios.
                            </p>
                        </div>
                    </div>

                </div>

                <div class="col-12 col-md-6">

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-calendar-check"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Ritos de acompanhamento
                            </h5>

                            <p class="text-white-50 mb-0">
                                Report semanal automático, pronto pra revisar e emitir.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-list-check"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Resposta gerencial estruturada
                            </h5>

                            <p class="text-white-50 mb-0">
                                Da restrição identificada até a ação resolvida, com dono e prazo.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-shield-alt-2"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Segurança e conformidade
                            </h5>

                            <p class="text-white-50 mb-0">
                                Cada empresa isolada, dados protegidos com padrões modernos.
                            </p>
                        </div>
                    </div>

                </div>

            </div>

            <div class="row">


                <div class="col-md-12 col-xl-12">
                  <div class="card shadow-none bg-transparent border border-warning mb-3">
                    <div class="card-body">
                      <h5 class="card-title text-warning"><i class="bx bx-xs bx-error align-top me-2"></i>Sua empresa já usa nossa plataforma?</h5>
                      <p class="card-text text-warning">Neste caso, não crie uma nova conta. Peça ao Administrador da sua equipe para lhe enviar um convite. Este formulário gerará uma nova assinatura.</p>
                    </div>
                  </div>
                </div>
            </div>

      </div>
      <!-- RODAPÉ -->
      <div class="auth-footer">
          © 2026 <strong>DCF.eng — Planejamento & Controle de Obras</strong>. Todos os direitos reservados.
      </div>
    </div>
    <!-- /Left Text -->

    <!-- Register -->
    <div class="d-flex col-12 col-lg-5 col-xl-4 align-items-center authentication-bg p-sm-5 p-4">
      <div class="w-px-600 mx-auto">
        <!-- Logo -->
        <div class="app-brand mb-4">
          <a href="{{url('/')}}" class="app-brand-link gap-2 mb-2">
            <img src="{{ asset('assets/img/logos/logo_oficial.png') }}" alt="Logo DCF.eng" style="width: 50%;">
          </a>
        </div>
        <!-- /Logo -->
        <h4 class="mb-2">Comece a antecipar impedimentos hoje. 🚀</h4>
        <p class="mb-4">Configure sua primeira obra em poucos minutos e tenha indicadores, alertas e decisões em um único lugar.</p>

        <!-- Stepper -->
        <div class="d-flex gap-2 mb-4" id="wizardPills">
          @foreach ($steps as $key => $label)
            <span class="badge rounded-pill {{ $loop->first ? 'bg-primary' : 'bg-label-secondary' }} flex-fill py-2 text-truncate" data-pill="{{ $key }}">{{ $loop->iteration }}. {{ $label }}</span>
          @endforeach
        </div>

        @error('first_name') @php($erroNoPasso1 = true) @enderror
        @error('last_name') @php($erroNoPasso1 = true) @enderror
        @error('email') @php($erroNoPasso1 = true) @enderror
        @error('password') @php($erroNoPasso1 = true) @enderror
        @error('terms') @php($erroNoUltimoPasso = true) @enderror

        <form id="formAuthentication" class="mb-3" action="{{ route('register') }}" method="POST" novalidate>
          @csrf

          <!-- PASSO 1: DADOS DE ACESSO -->
          <div class="wizard-step" data-step="acesso">
            <div class="mb-3">
              <div class="row">
                <div class="col-6">
                    <label for="first_name" class="form-label">Primeiro Nome</label>
                    <input type="text" class="form-control @error('first_name') is-invalid @enderror" id="first_name" name="first_name" placeholder="Primeiro nome" required value="{{ old('first_name') }}" />
                    @error('first_name')
                    <span class="invalid-feedback" role="alert">
                      <span class="fw-medium">{{ $message }}</span>
                    </span>
                    @enderror
                </div>
                <div class="col-6">
                    <label for="last_name" class="form-label">Sobrenome</label>
                    <input type="text" class="form-control @error('last_name') is-invalid @enderror" id="last_name" name="last_name" placeholder="Último nome" required value="{{ old('last_name') }}" />
                    @error('last_name')
                    <span class="invalid-feedback" role="alert">
                      <span class="fw-medium">{{ $message }}</span>
                    </span>
                    @enderror
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label for="email" class="form-label">E-mail</label>
              <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" placeholder="voce@suaempresa.com.br" required value="{{ old('email') }}" />
              @error('email')
              <span class="invalid-feedback" role="alert">
                <span class="fw-medium">{{ $message }}</span>
              </span>
              @enderror
            </div>

            <div class="row">
              <div class="col-6">
                <div class="mb-3 form-password-toggle">
                  <label class="form-label" for="password">Senha</label>
                  <div class="input-group input-group-merge @error('password') is-invalid @enderror">
                    <input type="password" id="password" class="form-control @error('password') is-invalid @enderror" name="password" placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" aria-describedby="password" required />
                    <span class="input-group-text cursor-pointer">
                      <i class="bx bx-hide"></i>
                    </span>
                  </div>
                  @error('password')
                  <span class="invalid-feedback" role="alert">
                    <span class="fw-medium">{{ $message }}</span>
                  </span>
                  @enderror
                </div>
              </div>
              <div class="col-6">
                <div class="mb-3 form-password-toggle">
                  <label class="form-label" for="password-confirm">Confirmar Senha</label>
                  <div class="input-group input-group-merge">
                    <input type="password" id="password-confirm" class="form-control" name="password_confirmation" placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" aria-describedby="password" required />
                    <span class="input-group-text cursor-pointer">
                      <i class="bx bx-hide"></i>
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <button type="button" class="btn btn-primary d-grid w-100 mt-3" data-wizard-next>Continuar</button>
          </div>

          <!-- PASSO 2: DADOS DA EMPRESA -->
          <div class="wizard-step d-none" data-step="empresa">
            <div class="mb-3">
              <label for="company_name" class="form-label">Nome da Construtora</label>
              <input type="text" class="form-control @error('company_name') is-invalid @enderror" id="company_name" name="company_name" placeholder="Nome da Construtora" required value="{{ old('company_name') }}" />
              @error('company_name')
              <span class="invalid-feedback" role="alert">
                <span class="fw-medium">{{ $message }}</span>
              </span>
              @enderror
            </div>

            <div class="mb-3">
              <label for="razao_social" class="form-label">Razão Social <span class="text-muted fw-normal">(opcional)</span></label>
              <input type="text" class="form-control @error('razao_social') is-invalid @enderror" id="razao_social" name="razao_social" placeholder="Razão social da empresa" value="{{ old('razao_social') }}" />
              @error('razao_social')
              <span class="invalid-feedback" role="alert">
                <span class="fw-medium">{{ $message }}</span>
              </span>
              @enderror
            </div>

            <div class="mb-3">
              <label for="cnpj" class="form-label">CNPJ <span class="text-muted fw-normal">(opcional)</span></label>
              <input type="text" class="form-control @error('cnpj') is-invalid @enderror" id="cnpj" name="cnpj" placeholder="00.000.000/0000-00" maxlength="18" value="{{ old('cnpj') }}" />
              @error('cnpj')
              <span class="invalid-feedback" role="alert">
                <span class="fw-medium">{{ $message }}</span>
              </span>
              @enderror
            </div>

            <div class="d-flex gap-2 mt-3">
              <button type="button" class="btn btn-outline-secondary flex-fill" data-wizard-back>Voltar</button>
              <button type="button" class="btn btn-primary flex-fill" data-wizard-next>Continuar</button>
            </div>
          </div>

          @if ($planosAtivos->isNotEmpty())
          <!-- PASSO 3: ESCOLHA DO PLANO -->
          <div class="wizard-step d-none" data-step="plano">
            <p class="text-muted mb-3">Você começa com 7 dias de teste grátis no plano escolhido — sem cobrança nesta etapa.</p>

            <div class="row g-3 mb-3">
              @foreach ($planosAtivos as $plano)
                <div class="col-12">
                  <div class="card plano-card {{ $planosAtivos->count() === 1 || $plano->padrao_trial ? 'plano-card-selecionado' : '' }}" data-plano-card data-plano-id="{{ $plano->id }}" role="button" tabindex="0">
                    <div class="card-body d-flex justify-content-between align-items-center">
                      <div>
                        <h6 class="mb-1">{{ $plano->nome }}</h6>
                        <p class="text-muted small mb-0">
                          {{ $plano->max_obras ? $plano->max_obras.' obra(s)' : 'Obras ilimitadas' }}
                          ·
                          {{ $plano->max_usuarios ? $plano->max_usuarios.' usuário(s)' : 'Usuários ilimitados' }}
                        </p>
                      </div>
                      <div class="text-end">
                        <div class="fw-bold">R$ {{ number_format($plano->preco_mensal, 2, ',', '.') }}</div>
                        <div class="text-muted small">/mês</div>
                      </div>
                    </div>
                  </div>
                </div>
              @endforeach
            </div>

            <input type="hidden" name="plano_id" id="plano_id" value="{{ old('plano_id', $planosAtivos->count() === 1 ? $planosAtivos->first()->id : ($planosAtivos->firstWhere('padrao_trial', true)->id ?? '')) }}" />
            @error('plano_id')
            <div class="text-danger small mb-3">{{ $message }}</div>
            @enderror

            <div class="d-flex gap-2 mt-3">
              <button type="button" class="btn btn-outline-secondary flex-fill" data-wizard-back>Voltar</button>
              <button type="button" class="btn btn-primary flex-fill" data-wizard-next data-requer-plano>Continuar</button>
            </div>
          </div>
          @endif

          <!-- PASSO FINAL: REVISÃO + TERMOS -->
          <div class="wizard-step d-none" data-step="revisao">
            <div class="mb-3">
              <div class="form-check @error('terms') is-invalid @enderror">
                <input class="form-check-input @error('terms') is-invalid @enderror" type="checkbox" id="terms" name="terms" @if(old('terms')) checked @endif />
                <label class="form-check-label" for="terms">
                  Li e concordo com a
                  <a href="{{ route('policy.show') }}" target="_blank">Política de Privacidade</a> e os
                  <a href="{{ route('terms.show') }}" target="_blank">Termos de Uso</a>
                </label>
              </div>
              @error('terms')
                <div class="invalid-feedback" role="alert">
                    <span class="fw-medium">{{ $message }}</span>
                </div>
              @enderror
            </div>

            <div class="d-flex gap-2 mt-3">
              <button type="button" class="btn btn-outline-secondary flex-fill" data-wizard-back>Voltar</button>
              <button type="submit" class="btn btn-primary flex-fill">COMEÇAR AGORA</button>
            </div>
          </div>
        </form>

        <p class="text-center mt-2">
          <span>Já tem uma conta?</span>
          @if (Route::has('login'))
          <a href="{{ route('login') }}">
            <span><strong>Entre aqui</strong></span>
          </a>
          @endif
        </p>

      </div>
    </div>
    <!-- /Register -->
  </div>
</div>

<style>
  .plano-card { cursor: pointer; border: 1px solid var(--bs-border-color); transition: border-color .15s ease; }
  .plano-card:hover { border-color: var(--bs-primary); }
  .plano-card.plano-card-selecionado { border-color: var(--bs-primary); border-width: 2px; background-color: rgba(var(--bs-primary-rgb), .04); }
</style>

<script>
(function () {
  var steps = @json(array_keys($steps));
  var currentIndex = 0;

  var form = document.getElementById('formAuthentication');
  var stepEls = {};
  form.querySelectorAll('[data-step]').forEach(function (el) {
    stepEls[el.getAttribute('data-step')] = el;
  });
  var pillEls = {};
  document.querySelectorAll('[data-pill]').forEach(function (el) {
    pillEls[el.getAttribute('data-pill')] = el;
  });

  function mostrarPasso(index) {
    steps.forEach(function (nome, i) {
      stepEls[nome].classList.toggle('d-none', i !== index);

      var pill = pillEls[nome];
      pill.classList.remove('bg-primary', 'bg-success', 'bg-label-secondary');
      if (i === index) {
        pill.classList.add('bg-primary');
      } else if (i < index) {
        pill.classList.add('bg-success');
      } else {
        pill.classList.add('bg-label-secondary');
      }
    });
  }

  function passoValido(index) {
    var el = stepEls[steps[index]];
    var camposObrigatorios = el.querySelectorAll('[required]');
    for (var i = 0; i < camposObrigatorios.length; i++) {
      if (!camposObrigatorios[i].reportValidity()) {
        return false;
      }
    }

    var botaoAvancar = el.querySelector('[data-requer-plano]');
    if (botaoAvancar) {
      var planoId = document.getElementById('plano_id');
      if (!planoId || !planoId.value) {
        return false;
      }
    }

    return true;
  }

  form.querySelectorAll('[data-wizard-next]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!passoValido(currentIndex)) return;
      currentIndex = Math.min(currentIndex + 1, steps.length - 1);
      mostrarPasso(currentIndex);
    });
  });

  form.querySelectorAll('[data-wizard-back]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      currentIndex = Math.max(currentIndex - 1, 0);
      mostrarPasso(currentIndex);
    });
  });

  // Seleção de plano
  document.querySelectorAll('[data-plano-card]').forEach(function (card) {
    card.addEventListener('click', function () {
      document.querySelectorAll('[data-plano-card]').forEach(function (c) {
        c.classList.remove('plano-card-selecionado');
      });
      card.classList.add('plano-card-selecionado');
      document.getElementById('plano_id').value = card.getAttribute('data-plano-id');
    });
  });

  // Máscara de CNPJ (00.000.000/0000-00)
  var cnpjInput = document.getElementById('cnpj');
  if (cnpjInput) {
    cnpjInput.addEventListener('input', function () {
      var digitos = cnpjInput.value.replace(/\D/g, '').slice(0, 14);
      var formatado = digitos;
      if (digitos.length > 2) formatado = digitos.slice(0, 2) + '.' + digitos.slice(2);
      if (digitos.length > 5) formatado = formatado.slice(0, 6) + '.' + digitos.slice(5);
      if (digitos.length > 8) formatado = formatado.slice(0, 10) + '/' + digitos.slice(8);
      if (digitos.length > 12) formatado = formatado.slice(0, 15) + '-' + digitos.slice(12);
      cnpjInput.value = formatado;
    });
  }

  // Se o backend retornou erro de validação, reabre no passo correspondente
  @if (isset($erroNoPasso1) || isset($erroNoUltimoPasso))
    @if (isset($erroNoUltimoPasso) && ! isset($erroNoPasso1))
      currentIndex = steps.length - 1;
    @endif
    mostrarPasso(currentIndex);
  @endif
})();
</script>
@endsection
