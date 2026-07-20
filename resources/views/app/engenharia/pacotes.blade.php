@extends('layouts/layoutMaster')

@section('title', 'Lista de Documentos')

@section('vendor-script')
<script src="{{asset('assets/vendor/libs/chartjs/chartjs.js')}}"></script>
@endsection

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Engenharia /</span> Lista de Documentos
</h4>

<livewire:pages::engenharia.documentos-engenharia />
@endsection
