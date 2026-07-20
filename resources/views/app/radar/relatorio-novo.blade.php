@extends('layouts/layoutMaster')

@section('title', 'Novo Report — ' . $obraAtual->name)

@section('vendor-script')
<script src="{{asset('assets/vendor/libs/chartjs/chartjs.js')}}"></script>
@endsection

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Radar / <a href="{{ route('radar.relatorios') }}">Relatórios</a> /</span> Novo Report
</h4>

<livewire:pages::radar.relatorio-novo :obra="$obraAtual" />
@endsection
