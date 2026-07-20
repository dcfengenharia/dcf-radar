@extends('layouts/layoutMaster')

@section('title', 'Status de Documento')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Cadastros /</span> Status de Documento
</h4>

<livewire:pages::cadastros.status-documentos />
@endsection
