@extends('layouts/layoutMaster')

@section('title', 'Itens de Prontidão')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Cadastros /</span> Itens de Prontidão
</h4>

<livewire:pages::cadastros.itens-prontidao />
@endsection
