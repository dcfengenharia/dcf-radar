@php
$configData = Helper::appClasses();
$customizerHidden = 'customizer-hide';
@endphp

@extends('layouts.blankLayout')

@section('title', 'Aceitar Convite')

@section('page-style')
<!-- Page -->
<link rel="stylesheet" href="{{asset('assets/vendor/css/pages/page-auth.css')}}">
<link rel="stylesheet" href="{{ asset('assets/css/invite_dcf.css') }}">
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
                    Você foi
                    <span class="text-warning">
                        convidado! 🎉
                    </span>
                </h1>

                <p class="fs-4 text-white-50">
                    Complete seu cadastro e comece a acompanhar a obra
                    <mark class="bg-warning text-dark"> <strong>{{ $convite->obra->name }}</strong> </mark>
                </p>

            </div>
      </div>
      <!-- RODAPÉ -->
      <div class="auth-footer">
          © 2026 Desenvolvido com 🍺 por <strong>DCF.eng — Planejamento & Controle de Obras</strong>. Todos os direitos reservados.
      </div>
    </div>
    <!-- /Left Text -->


    <!-- Aceitar Convite -->
    <div class="d-flex col-12 col-lg-5 col-xl-4 align-items-center authentication-bg p-4 p-sm-5">
      <div class="w-px-600 mx-auto">
        <!-- Logo -->
        <div class="app-brand mb-4">
          <a href="{{url('/')}}" class="app-brand-link gap-2 mb-2">
            <img src="{{ asset('assets/img/logos/logo_oficial.png') }}" alt="Logo DCF.eng" style="width: 50%;">
          </a>
        </div>
        <!-- /Logo -->

        <h4 class="mb-2">Aí sim ein ... 😜</h4>
        <p class="text-muted mb-3">
          <strong>{{ $convite->convidadoPor->first_name }} {{ $convite->convidadoPor->last_name }}</strong>
          te enviou um convite para participar da obra <strong>{{ $convite->obra->name }}</strong>
          como <strong>{{ $convite->perfil->nome }}</strong>.
        </p>

        <form id="formAceitarConvite" class="mb-3" action="{{ route('convite.aceitar', $convite->token) }}" method="POST">
          @csrf

          <div class="mb-3">
            <label for="email" class="form-label">E-mail</label>
            <input type="email" class="form-control" id="email" value="{{ $convite->email }}" readonly disabled />
          </div>

          <div class="row">
            <div class="col-md-6 mb-3">
              <label for="first_name" class="form-label">Nome</label>
              <input type="text" class="form-control @error('first_name') is-invalid @enderror"
                     id="first_name" name="first_name" value="{{ old('first_name') }}" autofocus />
              @error('first_name')
              <span class="invalid-feedback" role="alert"><span class="fw-medium">{{ $message }}</span></span>
              @enderror
            </div>
            <div class="col-md-6 mb-3">
              <label for="last_name" class="form-label">Sobrenome</label>
              <input type="text" class="form-control @error('last_name') is-invalid @enderror"
                     id="last_name" name="last_name" value="{{ old('last_name') }}" />
              @error('last_name')
              <span class="invalid-feedback" role="alert"><span class="fw-medium">{{ $message }}</span></span>
              @enderror
            </div>
          </div>

          <div class="mb-3 form-password-toggle">
            <label class="form-label" for="password">Senha</label>
            <div class="input-group input-group-merge @error('password') is-invalid @enderror">
              <input type="password" id="password" class="form-control @error('password') is-invalid @enderror"
                     name="password" placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" />
              <span class="input-group-text cursor-pointer"><i class="bx bx-hide"></i></span>
            </div>
            @error('password')
            <span class="invalid-feedback" role="alert"><span class="fw-medium">{{ $message }}</span></span>
            @enderror
          </div>

          <div class="mb-3 form-password-toggle">
            <label class="form-label" for="password_confirmation">Confirmar Senha</label>
            <div class="input-group input-group-merge">
              <input type="password" id="password_confirmation" class="form-control"
                     name="password_confirmation" placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" />
              <span class="input-group-text cursor-pointer"><i class="bx bx-hide"></i></span>
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
            <div class="invalid-feedback" role="alert"><span class="fw-medium">{{ $message }}</span></div>
            @enderror
          </div>
          @endif

          <button type="submit" class="btn btn-primary d-grid w-100 mb-3">
            CRIAR CONTA E SER FELIZ 🥳
          </button>
        </form>
      </div>
    </div>
    <!-- /Aceitar Convite -->
  </div>
</div>
@endsection
