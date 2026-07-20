@extends('layouts/layoutMaster')

@section('title', 'Notificações')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Conta /</span> Notificações
</h4>

<livewire:pages::notificacoes.index/>

@endsection
