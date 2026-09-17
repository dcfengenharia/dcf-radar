@extends('layouts/layoutMaster')

@section('title', 'Histórico de Acessos')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Configurações /</span> Histórico de Acessos
</h4>

<livewire:pages::gestao.historico-acessos />
@endsection
