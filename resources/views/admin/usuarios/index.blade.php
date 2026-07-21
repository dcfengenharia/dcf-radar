@extends('layouts/layoutAdmin')

@section('title', 'Usuários')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Administração /</span> Usuários
</h4>

<livewire:pages::admin.usuarios.index />
@endsection
