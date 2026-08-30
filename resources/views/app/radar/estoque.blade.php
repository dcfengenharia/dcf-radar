@extends('layouts/layoutMaster')

@section('title', 'Estoque')

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Radar /</span> Estoque
</h4>

<livewire:pages::radar.estoque />
@endsection
