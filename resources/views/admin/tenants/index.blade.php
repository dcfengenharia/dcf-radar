@extends('layouts/layoutMaster')

@section('title', 'Contas (Tenants)')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Administração /</span> Contas (Tenants)
</h4>

<livewire:pages::admin.tenants.index />
@endsection
