@php
$configData = Helper::appClasses();
$customizerHidden = 'customizer-hide';
@endphp

@extends('layouts.blankLayout')

@section('title', 'Login')

@section('page-style')
<!-- Page -->
<link rel="stylesheet" href="{{asset('assets/vendor/css/pages/page-auth.css')}}">
<link rel="stylesheet" href="{{ asset('assets/css/login_dcf.css') }}">
@endsection

@section('content')
<div class="authentication-wrapper authentication-cover">
  <div class="authentication-inner row m-0">


    <!-- /Lado Esquerdo -->
    <div class="d-none d-lg-flex col-lg-7 col-xl-8 align-items-center auth-left-side position-relative overflow-hidden">

      <!-- OVERLAY -->
      <div class="auth-overlay"></div>
      <!-- CONTENT -->
      <div class="w-100 px-5 py-5 text-white position-relative z-2">

          <!-- BADGE -->
          <div class="auth-badge mb-4">
            #planejamentoSemChatice
          </div>

          <!-- HEADLINE -->
          <div class="mb-5">

              <h1 class="auth-left-title">
                  A previsibilidade da obra começa com
                  <span>
                      leitura operacional
                  </span>
              </h1>

              <p class="auth-left-subtitle">
                  Monitore sinais críticos, identifique desvios
                  e tome decisões com mais antecedência.
              </p>

          </div>

          <!-- BENEFITS -->
          <div class="row auth-benefits">

              <div class="col-md-6">

                  <div class="auth-benefit-item">
                      <div class="me-3">
                          <div class="avatar flex-shrink-0 me-3">
                            <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                          </div>
                      </div>

                      <div>
                          <h5>Indicadores de previsibilidade</h5>
                          <p>Visibilidade clara sobre desempenho e risco operacional.</p>
                      </div>
                  </div>

                  <div class="auth-benefit-item">
                      <div class="me-3">
                          <div class="avatar flex-shrink-0 me-3">
                            <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                          </div>
                      </div>

                      <div>
                          <h5>Alertas operacionais</h5>
                          <p>Sinais antecipados de deterioração operacional.</p>
                      </div>
                  </div>

              </div>

              <div class="col-md-6">

                  <div class="auth-benefit-item">
                      <div class="me-3">
                          <div class="avatar flex-shrink-0 me-3">
                            <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                          </div>
                      </div>

                      <div>
                          <h5>Ritos de acompanhamento</h5>
                          <p>Rotinas estruturadas para controle da operação.</p>
                      </div>
                  </div>

                  <div class="auth-benefit-item">
                      <div class="me-3">
                          <div class="avatar flex-shrink-0 me-3">
                            <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-video"></i></span>
                          </div>
                      </div>

                      <div>
                          <h5>Resposta gerencial</h5>
                          <p>Decisões orientadas por dados e tendência operacional.</p>
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
    <!-- /Lado Esquerdo -->

    <!-- Login -->
    <div class="d-flex col-12 col-lg-5 col-xl-4 align-items-center authentication-bg p-sm-5 p-4">
      <div class="w-px-400 mx-auto">
        <!-- Logo -->
        <div class="app-brand mb-4">
          <a href="{{url('/')}}" class="app-brand-link gap-2 mb-2">
            <img src="{{ asset('assets/img/logos/logo_oficial.png') }}" alt="Logo DCF.eng" style="width: 70%;">
          </a>
        </div>
        <!-- /Logo -->
        <h4 class="mb-2">Seja bem-vindo ao {{config('variables.templateName')}}! 👋</h4>
        <p class="mb-4">Acesse seu radar operacional.</p>

          @if (session('status'))
          <div class="alert alert-success mb-1 rounded-0" role="alert">
            <div class="alert-body">
              {{ session('status') }}
            </div>
          </div>
          @endif

          <form id="formAuthentication" class="mb-3" action="{{ route('login') }}" method="POST">
            @csrf
            <div class="mb-3">
              <label for="login-email" class="form-label">E-mail</label>
              <input type="text" class="form-control @error('email') is-invalid @enderror" id="login-email" name="email" placeholder="seu-email@exemplo.com" autofocus value="{{ old('email') }}">
              @error('email')
              <span class="invalid-feedback" role="alert">
                <span class="fw-medium">{{ $message }}</span>
              </span>
              @enderror
            </div>
            <div class="mb-3 form-password-toggle">
              <div class="d-flex justify-content-between">
                <label class="form-label" for="login-password">Senha</label>
                @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}">
                  <small>Esqueceu sua senha?</small>
                </a>
                @endif
              </div>
              <div class="input-group input-group-merge @error('password') is-invalid @enderror">
                <input type="password" id="login-password" class="form-control @error('password') is-invalid @enderror" name="password" placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" aria-describedby="password" />
                <span class="input-group-text cursor-pointer"><i class="bx bx-hide"></i></span>
              </div>
              @error('password')
              <span class="invalid-feedback" role="alert">
                <span class="fw-medium">{{ $message }}</span>
              </span>
              @enderror
            </div>
            <div class="mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remember-me" name="remember" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label" for="remember-me">Manter conectado</label>
              </div>
            </div>
            <button class="btn btn-primary d-grid w-100" type="submit">ACESSAR RADAR OPERACIONAL</button>
          </form>

          <p class="text-center">
            <span>Novo na nossa plataforma?</span>
            @if (Route::has('register'))
            <a href="{{ route('register') }}">
              <span><strong>Criar uma conta</strong></span>
            </a>
            @endif
          </p>


        </div>
      </div>
    <!-- /Login -->
  </div>
</div>
@endsection
