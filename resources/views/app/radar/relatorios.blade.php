@extends('layouts/layoutMaster')

@section('title', 'Relatórios — ' . $obraAtual->name)

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Radar /</span> Relatórios
</h4>

<livewire:pages::radar.relatorios :obra="$obraAtual" />
@endsection
