@extends('layouts/layoutAdmin')

@section('title', 'Planos')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Administração /</span> Planos
</h4>

<livewire:pages::admin.planos.index />
@endsection
