@extends('layouts/layoutMaster')

@section('title', 'Convite por E-mail')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Cadastros /</span> Convite por E-mail
</h4>

<livewire:pages::cadastros.convite-config />
@endsection
