@extends('layouts/layoutAdmin')

@section('title', 'Conta — ' . $tenant->name)

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Administração / <a href="{{ route('admin.tenants.index') }}">Contas</a> /</span> {{ $tenant->name }}
</h4>

<livewire:pages::admin.tenants.show :tenant="$tenant" />
@endsection
