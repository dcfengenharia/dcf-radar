@php
$configData = Helper::appClasses();
$customizerHidden = 'customizer-hide';
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
                    Transforme dados da obra em
                    <span class="text-warning">
                        previsibilidade real
                    </span>
                </h1>

                <p class="fs-4 text-white-50">
                    Monitore indicadores, antecipe riscos e conduza decisões
                    com mais clareza, controle e velocidade de resposta.
                </p>

            </div>

            <!-- BENEFÍCIOS -->
            <div class="row mt-5">

                <div class="col-12 col-md-6">

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Indicadores de previsibilidade
                            </h5>

                            <p class="text-white-50 mb-0">
                                Acompanhe o que realmente impacta prazo e desempenho.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Alertas inteligentes
                            </h5>

                            <p class="text-white-50 mb-0">
                                Identifique desvios antes que virem problemas críticos.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Tendência de prazo
                            </h5>

                            <p class="text-white-50 mb-0">
                                Antecipe impactos e tome decisões com mais confiança.
                            </p>
                        </div>
                    </div>

                </div>

                <div class="col-12 col-md-6">

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Ritos de acompanhamento
                            </h5>

                            <p class="text-white-50 mb-0">
                                Reuniões guiadas e pautas estruturadas automaticamente.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Resposta gerencial estruturada
                            </h5>

                            <p class="text-white-50 mb-0">
                                Da identificação do problema até o plano de ação.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex mb-4">
                        <div class="me-3">
                            <div class="avatar flex-shrink-0 me-3">
                              <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                            </div>
                        </div>

                        <div>
                            <h5 class="text-white mb-1">
                                Segurança e conformidade
                            </h5>

                            <p class="text-white-50 mb-0">
                                Seus dados protegidos com padrões modernos de segurança.
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
          © 2026 Desenvolvido com 🍺 por <strong>DCF.eng — Planejamento & Controle de Obras</strong>. Todos os direitos reservados.
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
        <h4 class="mb-2">Uma nova maneira de olhar cronogramas. 🚀</h4>
        <p class="mb-4">Configure sua primeira obra em poucos minutos e tenha indicadores, alertas e decisões em um único lugar.</p>

        <form id="formAuthentication" class="mb-3" action="{{ route('register') }}" method="POST">
          @csrf
          <!-- nome e sobrenome-->
          <div class="mb-3">
            <div class="row">
              <div class="col-6">
                  <label for="first_name" class="form-label">Primeiro Nome</label>
                  <input type="text" class="form-control @error('first_name') is-invalid @enderror" id="first_name" name="first_name" placeholder="Primeiro nome" autofocus value="{{ old('first_name') }}" />
                  @error('first_name')
                  <span class="invalid-feedback" role="alert">
                    <span class="fw-medium">{{ $message }}</span>
                  </span>
                  @enderror
              </div>
              <div class="col-6">
                  <label for="last_name" class="form-label">Sobrenome</label>
                  <input type="text" class="form-control @error('last_name') is-invalid @enderror" id="last_name" name="last_name" placeholder="Último nome" autofocus value="{{ old('last_name') }}" />
                  @error('last_name')
                  <span class="invalid-feedback" role="alert">
                    <span class="fw-medium">{{ $message }}</span>
                  </span>
                  @enderror
              </div>
            </div>
          </div>
          <!-- nome da empresa -->
          <div class="mb-3">
            <label for="company_name" class="form-label">Nome da Construtora</label>
            <input type="text" class="form-control @error('company_name') is-invalid @enderror" id="company_name" name="company_name" placeholder="Nome da Construtora" value="{{ old('company_name') }}" />
            @error('company_name')
            <span class="invalid-feedback" role="alert">
              <span class="fw-medium">{{ $message }}</span>
            </span>
            @enderror
          </div>
          <!-- e-mail -->
          <div class="mb-3">
            <label for="email" class="form-label">E-mail</label>
            <input type="text" class="form-control @error('email') is-invalid @enderror" id="email" name="email" placeholder="john@example.com" value="{{ old('email') }}" />
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
                  <input type="password" id="password" class="form-control @error('password') is-invalid @enderror" name="password" placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" aria-describedby="password" />
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
                  <input type="password" id="password-confirm" class="form-control" name="password_confirmation" placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" aria-describedby="password" />
                  <span class="input-group-text cursor-pointer">
                    <i class="bx bx-hide"></i>
                  </span>
                </div>
              </div>
            </div>
          </div>

          @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
            <div class="mb-3">
              <div class="form-check @error('terms') is-invalid @enderror">
                <input class="form-check-input @error('terms') is-invalid @enderror" type="checkbox" id="terms" name="terms" />
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
          @endif
          <button type="submit" class="btn btn-primary d-grid w-100 mt-3">COMEÇAR AGORA</button>
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
@endsection
