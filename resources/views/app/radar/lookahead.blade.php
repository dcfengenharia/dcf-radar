@extends('layouts/layoutMaster')

@section('title', 'Lookahead — ' . $obraAtual->name)

@section('vendor-script')
<script src="{{asset('assets/vendor/libs/chartjs/chartjs.js')}}"></script>
@endsection

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Radar /</span> Lookahead Lean
</h4>

<div class="alert alert-primary d-flex align-items-center gap-2 py-2 mb-4">
    🏢
    <span>OBRA ATIVA: <strong>{{ $obraAtual->name }}</strong></span>
    <a href="{{ route('gestao.minhas-obras') }}" class="ms-auto btn btn-sm btn-outline-primary">
        <i class="bx bx-transfer me-1"></i>Trocar obra
    </a>
</div>

<livewire:pages::radar.lookahead :obra="$obraAtual" />
@endsection
