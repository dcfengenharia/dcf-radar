@php
$configData = Helper::appClasses();
$customizerHidden = 'customizer-hide';
@endphp

@extends('layouts.blankLayout')

@section('title', 'Esqueci Minha Senha')

@section('page-style')
<!-- Page -->
<link rel="stylesheet" href="{{asset('assets/vendor/css/pages/page-auth.css')}}">
<link rel="stylesheet" href="{{ asset('assets/css/forgot_password_dcf.css') }}">
@endsection

@section('content')
<div class="authentication-wrapper authentication-cover">
  <div class="authentication-inner row m-0">

    <!-- /Lado Esquerdo -->
    <div class="d-none d-lg-flex col-lg-7 col-xl-8 align-items-center d-none d-lg-flex auth-left-side">
      <div class="auth-overlay"></div>
      <div class="w-100 px-5 py-5 text-white position-relative z-2">

          <!-- BADGE -->
          <div class="auth-badge mb-4">
            #issoAconteceComOMelhorEncarregado
          </div>

          <!-- HEADLINE -->
          <div class="mb-4" style="max-width: 620px;">

              <h1 class="display-4 fw-bold text-white lh-1 mb-4">
                  Esqueceu a senha?
                  <span class="text-warning">
                      Relaxa, isso é bem menor que um desvio de prazo.
                  </span>
              </h1>

              <p class="fs-4 text-white-50">
                  Em menos de um minuto você define uma senha nova e volta
                  direto pro Quadro de Restrições — o cronograma não vai
                  a lugar nenhum enquanto isso.
              </p>

          </div>

      </div>
      <!-- RODAPÉ -->
      <div class="auth-footer">
          © 2026 <strong>DCF.eng — Planejamento & Controle de Obras</strong>. Todos os direitos reservados.
      </div>
    </div>
    <!-- /Lado Esquerdo -->

    <!-- Esqueci Minha Senha -->
    <div class="d-flex col-12 col-lg-5 col-xl-4 align-items-center authentication-bg p-sm-5 p-4">
      <div class="w-px-400 mx-auto">
        <!-- Logo -->
        <div class="app-brand mb-4">
          <a href="{{url('/')}}" class="app-brand-link gap-2 mb-2">
            <img src="{{ asset('assets/img/logos/logo_oficial.png') }}" alt="Logo DCF.eng" style="width: 70%;">
          </a>
        </div>
        <!-- /Logo -->
        <h4 class="mb-2">Esqueceu a senha? Beleza, sem drama. 🔑</h4>
        <p class="mb-4">Digite seu e-mail e mandamos um link pra você criar uma senha nova.</p>

        @if (session('status'))
        <div class="alert alert-success mb-1 rounded-0" role="alert">
          <div class="alert-body">
            {{ session('status') }}
          </div>
        </div>
        @endif

        <form id="formAuthentication" class="mb-3" action="{{ route('password.email') }}" method="POST">
          @csrf
          <div class="mb-3">
            <label for="email" class="form-label">E-mail</label>
            <input type="text" class="form-control @error('email') is-invalid @enderror" id="email" name="email" placeholder="seu-email@exemplo.com" autofocus value="{{ old('email') }}">
            @error('email')
            <span class="invalid-feedback" role="alert">
              <span class="fw-medium">{{ $message }}</span>
            </span>
            @enderror
          </div>
          <button type="submit" class="btn btn-primary d-grid w-100">ENVIAR LINK DE REDEFINIÇÃO</button>
        </form>
        <div class="text-center">
          @if (Route::has('login'))
          <a href="{{ route('login') }}" class="d-flex align-items-center justify-content-center">
            <i class="bx bx-chevron-left scaleX-n1-rtl"></i>
            Voltar pro login
          </a>
          @endif
        </div>
      </div>
    </div>
    <!-- /Esqueci Minha Senha -->
  </div>
</div>
@endsection
