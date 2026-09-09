@extends('layouts/layoutMaster')

@section('title', 'Famílias de Materiais')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Cadastros /</span> Famílias de Materiais
</h4>

<livewire:pages::cadastros.familias-material />
@endsection
