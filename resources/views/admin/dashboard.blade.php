@extends('layouts/layoutAdmin')

@section('title', 'Painel do Proprietário')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Administração /</span> Painel do Proprietário
</h4>

<livewire:pages::admin.dashboard />
@endsection
