@extends('layouts/layoutMaster')

@section('title', 'Dados da Empresa')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Empresa /</span> Dados da Empresa
</h4>

<livewire:pages::empresa.perfil :tenant="$tenant" />
@endsection
