@extends('layouts/layoutMaster')

@section('title', 'Assinatura')

@section('content')
<script src="https://sdk.mercadopago.com/js/v2"></script>

<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Empresa /</span> Assinatura
</h4>

<livewire:pages::empresa.assinatura :tenant="$tenant" />
@endsection
