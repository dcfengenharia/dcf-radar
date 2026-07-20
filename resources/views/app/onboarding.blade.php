@extends('layouts.layoutMaster')
@php
  $configData = Helper::appClasses();
@endphp
@section('title', 'Configuração Inicial')

@section('content')
@php
  use App\Support\Onboarding\OnboardingChecklist;
  use App\Support\ObraContext;

  $passosTenant = OnboardingChecklist::passosTenant();
  $obraAtual = ObraContext::current();
  $passosObra = $obraAtual ? OnboardingChecklist::passosObra($obraAtual) : [];
  $todosPassos = array_merge($passosTenant, $passosObra);
  $concluidos = count(array_filter($todosPassos, fn ($passo) => $passo->estaConcluido()));
  $total = count($todosPassos);
@endphp

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
    <div>
        <h4 class="mb-1 mt-2">⚠️ Onboarding</h4>
        <p class="text-muted mb-0">Se esta tela está aparecendo para você, significa que precisa concluir alguns cadastros básicos no sistema antes de prosseguir.</p>
    </div>
</div>

<div class="card mb-4 border shadow-none">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h4 class="mb-1">Configuração Inicial</h4>
        <p class="mb-0">{{ $concluidos }} de {{ $total }} passos concluídos.</p>
      </div>
      <div class="text-end">
        <h3 class="mb-0 {{ $concluidos === $total ? 'text-success' : 'text-warning' }}">
          {{ $total > 0 ? round(($concluidos / $total) * 100) : 0 }}%
        </h3>
      </div>
    </div>
    <div class="progress" style="height: 8px;">
      <div class="progress-bar {{ $concluidos === $total ? 'bg-success' : 'bg-warning' }}"
           role="progressbar"
           style="width: {{ $total > 0 ? ($concluidos / $total) * 100 : 0 }}%"></div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-12 col-md-6">
    <div class="card h-100">
      <div class="card-header">
        <h5 class="mb-0">Configuração da Plataforma</h5>
      </div>
      <ul class="list-group list-group-flush">
        @foreach ($passosTenant as $passo)
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <i class='bx {{ $passo->estaConcluido() ? "bx-check-circle text-success" : "bx-radio-circle text-warning" }} me-2'></i>
              <span class="mb-4"><strong>{{ $passo->titulo }}</strong></span>
              @unless ($passo->obrigatorio)
                <span class="badge bg-label-secondary ms-1">opcional</span>
              @endunless
              <div class="text-muted small ms-4">{{ $passo->descricao }}</div>
            </div>
            @unless ($passo->estaConcluido())
              <a href="{{ route($passo->rotaAcao) }}" class="btn btn-sm btn-outline-primary">{{ $passo->rotuloAcao }}</a>
            @endunless
          </li>
        @endforeach
      </ul>
    </div>
  </div>

  <div class="col-12 col-md-6">
    <div class="card h-100">
      <div class="card-header">
        <h5 class="mb-0">Configuração da Obra Atual</h5>
      </div>
      @if ($obraAtual)
        <ul class="list-group list-group-flush">
          @foreach ($passosObra as $passo)
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <div>
                <i class='bx {{ $passo->estaConcluido() ? "bx-check-circle text-success" : "bx-radio-circle text-warning" }} me-2'></i>
                <strong>{{ $passo->titulo }}</strong>
                @unless ($passo->obrigatorio)
                  <span class="badge bg-label-secondary ms-1">opcional</span>
                @endunless
                <div class="text-muted small ms-4">{{ $passo->descricao }}</div>
              </div>
              @unless ($passo->estaConcluido())
                <a href="{{ route($passo->rotaAcao) }}" class="btn btn-sm btn-outline-primary">{{ $passo->rotuloAcao }}</a>
              @endunless
            </li>
          @endforeach
        </ul>
      @else
        <div class="card-body">
          <p class="mb-2">Nenhuma obra selecionada.</p>
          <a href="{{ route('gestao.minhas-obras') }}" class="btn btn-sm btn-primary">Selecionar Obra</a>
        </div>
      @endif
    </div>
  </div>
</div>
@endsection
