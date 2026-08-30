@extends('layouts/layoutMaster')

@section('title', 'Requisições do Planejamento')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Planejamento /</span> Requisições do Planejamento
</h4>

<livewire:pages::planejamento.requisicoes-planejamento />
@endsection
