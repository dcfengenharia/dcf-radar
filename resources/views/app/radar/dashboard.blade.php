@extends('layouts/layoutMaster')

@section('title', 'Dashboard — ' . $obraAtual->name)

@section('vendor-script')
<script src="{{asset('assets/vendor/libs/chartjs/chartjs.js')}}"></script>
@endsection

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Radar /</span> Dashboard
</h4>

<livewire:pages::radar.dashboard :obra="$obraAtual" />
@endsection
