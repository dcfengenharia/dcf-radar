@extends('layouts/layoutMaster')

@section('title', 'Feriados')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Cadastros /</span> Feriados
</h4>

<livewire:pages::cadastros.feriados />
@endsection
