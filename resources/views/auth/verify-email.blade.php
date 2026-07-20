@php
$configData = Helper::appClasses();
$customizerHidden = 'customizer-hide';
@endphp

@extends('layouts/blankLayout')

@section('title', 'Verifique seu e-mail')

@section('page-style')
{{-- Page Css files --}}
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/css/pages/page-auth.css')) }}">
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
            <div class="mb-4 w-100">

                <h1 class="display-4 fw-bold text-white lh-1 mb-4">
                    Estamos
                    <span class="text-warning">
                        quase lá!
                    </span>
                </h1>

                <p class="fs-4 text-white-50">
                    Nossa plataforma está pronta para você. Vá até a sua caixa de entrada agora mesmo, confirme seu e-mail e desbloqueie o acesso instantâneo ao mais prático ecossistema de previsibilidade de engenharia.
                </p>

            </div>

            <div class="container py-5">
              <div class="row text-center align-items-start">

                  <!-- PASSO 1: Cadastro -->
                  <div class="col-12 col-md-3 mb-4 position-relative">
                      <!-- Círculo e Ícone -->
                      <div class="d-flex justify-content-center mb-3">
                          <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow" style="width: 80px; height: 80px;">
                              <i class="bx bx-user-plus" style="font-size: 2.2rem;"></i>
                          </div>
                      </div>
                      <!-- Textos -->
                      <h5 class="fw-bold text-heading" style="text-transform: uppercase;">
                          1. Cadastro
                      </h5>
                      <p class="text-muted small px-2">
                          Crie sua conta em poucos passos e prepare-se para transformar a gestão dos seus projetos de construção.
                      </p>
                      <!-- Seta apontando para o próximo (Oculta em mobile) -->
                      <div class="position-absolute d-none d-md-block" style="top: 25px; right: -15px;">
                          <i class="bx bx-right-arrow-alt text-muted" style="font-size: 2rem;"></i>
                      </div>
                  </div>

                  <!-- PASSO 2: Confirmação -->
                  <div class="col-12 col-md-3 mb-4 position-relative">
                      <div class="d-flex justify-content-center mb-3">
                          <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow" style="width: 80px; height: 80px;">
                              <i class="bx bx-envelope" style="font-size: 2.2rem;"></i>
                          </div>
                      </div>
                      <h5 class="fw-bold text-heading" style="text-transform: uppercase;">2. Confirmação</h5>
                      <p class="text-muted small px-2">
                          Verificação de segurança do e-mail para liberar as ferramentas.
                      </p>
                      <div class="position-absolute d-none d-md-block" style="top: 25px; right: -15px;">
                          <i class="bx bx-right-arrow-alt text-muted" style="font-size: 2rem;"></i>
                      </div>
                  </div>

                  <!-- PASSO 3: Cadastro da Obra -->
                  <div class="col-12 col-md-3 mb-4 position-relative">
                      <div class="d-flex justify-content-center mb-3">
                          <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow" style="width: 80px; height: 80px;">
                              <i class="bx bx-buildings" style="font-size: 2.2rem;"></i>
                          </div>
                      </div>
                      <h5 class="fw-bold text-heading" style="text-transform: uppercase;">3. Primeira Obra</h5>
                      <p class="text-muted small px-2">
                          Cadastro do cliente e inicialização do seu primeiro canteiro de obras.
                      </p>
                      <div class="position-absolute d-none d-md-block" style="top: 25px; right: -15px;">
                          <i class="bx bx-right-arrow-alt text-muted" style="font-size: 2rem;"></i>
                      </div>
                  </div>

                  <!-- PASSO 4: Análise -->
                  <div class="col-12 col-md-3 mb-4 position-relative">
                      <div class="d-flex justify-content-center mb-3">
                          <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow" style="width: 80px; height: 80px;">
                              <i class="bx bx-line-chart" style="font-size: 2.2rem;"></i>
                          </div>
                      </div>
                      <h5 class="fw-bold text-heading" style="text-transform: uppercase;">4. Análise</h5>
                      <p class="text-muted small px-2">
                          Importe cronogramas, gere a Curva S e extraia relatórios gerenciais.
                      </p>
                      <!-- Nota: O último passo não tem seta -->
                  </div>

              </div>
            </div>

            <div class="row">
                <div class="col-md-12 col-xl-12">
                  <div class="card shadow-none bg-transparent border border-warning mb-3">
                    <div class="card-body">
                      <h5 class="card-title text-warning">⏱️ Não deixe o planejamento da sua obra esperando.</h5>
                      <p class="card-text text-warning">
                        O link de ativação já foi enviado. Clique no botão dentro do e-mail e comece a extrair indicadores reais de saúde dos seus projetos.
                      </p>
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

    <!--  Verify email -->
    <div class="d-flex col-12 col-lg-5 col-xl-4 align-items-center authentication-bg p-4 p-sm-5">
      <div class="w-px-600 mx-auto">
        <!-- Logo -->
        <div class="app-brand mb-4">
          <a href="{{url('/')}}" class="app-brand-link gap-2 mb-2">
            <img src="{{ asset('assets/img/logos/logo_oficial.png') }}" alt="Logo DCF.eng" style="width: 50%;">
          </a>
        </div>
        <!-- /Logo -->
        <h4 class="mb-4">Verifique seu e-mail ✉️</h4>
        @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success" role="alert">
          <div class="alert-body">
            Um link de verificação foi enviado para o endereço de e-mail que você forneceu durante o registro.
          </div>
        </div>
        @endif
        <p class="text-start">
          Enviamos um link de ativação de conta para o e-mail: <mark><span class="fw-medium">{{Auth::user()->email}}</span></mark>.
          <br><br>
          Por favor, verifique sua caixa de entrada (e caixa de spam) para confirmar sua conta e acessar a plataforma.
          <br><br>
          Se você não recebeu o e-mail, clique no botão abaixo para solicitar outro link de verificação.
        </p>
        <div class="mt-4">
          <div class="row">
            <div class="col-12">
              <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="btn btn-label-secondary w-100">Reenviar E-mail</button>
              </form>
            </div>
            <div class="col-12 mt-4">
              <form method="POST" action="{{route('logout')}}">
                @csrf
                <button type="submit" class="btn btn-danger w-100">Sair</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- / Verify Email -->
  </div>
</div>
@endsection
