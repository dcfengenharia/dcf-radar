@extends('layouts/layoutMaster')

@section('title', 'Perfis de Acesso')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Configurações /</span> Perfis de Acesso
</h4>

<livewire:pages::gestao.perfis-acesso />
@endsection
