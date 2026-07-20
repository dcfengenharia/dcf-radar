
@extends('layouts/layoutMaster')

@section('title', 'Minhas Obras')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Gestão /</span> Minhas Obras
</h4>

<livewire:pages::gestao.minhas-obras/>

@endsection
