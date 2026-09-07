@extends('layouts/layoutMaster')

@section('title', 'Lições Aprendidas')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Gestão /</span> Lições Aprendidas
</h4>

<livewire:pages::gestao.licoes-aprendidas />

@endsection
