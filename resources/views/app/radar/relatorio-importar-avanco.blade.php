@extends('layouts/layoutMaster')

@section('title', 'Importar Avanço — ' . $obraAtual->name)

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Radar / Relatórios /</span> Importar Avanço
</h4>

<div class="alert alert-primary d-flex align-items-center gap-2 py-2 mb-4">
    🏢
    <span>OBRA ATIVA: <strong>{{ $obraAtual->name }}</strong></span>
    <a href="{{ route('gestao.minhas-obras') }}" class="ms-auto btn btn-sm btn-outline-primary">
        <i class="bx bx-transfer me-1"></i>Trocar obra
    </a>
</div>

<livewire:pages::radar.relatorio-importar-avanco :obra="$obraAtual" />
@endsection
