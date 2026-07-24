
@extends('layouts/layoutMaster')

@section('title', 'Detalhes da Obra — ' . $obra->name)

@section('vendor-style')
<link rel="stylesheet" href="{{asset('assets/vendor/libs/dhtmlx-gantt/dhtmlxgantt.css')}}">
@endsection

@section('vendor-script')
<script src="{{asset('assets/vendor/libs/dhtmlx-gantt/dhtmlxgantt.js')}}"></script>
@endsection

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Gestão / <a href="{{ route('gestao.minhas-obras') }}">Minhas Obras</a> /</span> {{ $obra->name }}
</h4>

<livewire:pages::gestao.obra-detalhe :obra="$obra"/>

@endsection
