@extends('layouts/layoutMaster')

@section('title', 'Fornecedores')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Cadastros /</span> Fornecedores
</h4>

<livewire:pages::cadastros.fornecedores />
@endsection
