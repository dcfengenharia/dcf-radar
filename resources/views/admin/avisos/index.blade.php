@extends('layouts/layoutAdmin')

@section('title', 'Avisos aos Usuários')

@section('vendor-style')
<link rel="stylesheet" href="{{asset(mix('assets/vendor/libs/quill/editor.css'))}}">
@endsection

@section('vendor-script')
<script src="{{asset(mix('assets/vendor/libs/quill/quill.js'))}}"></script>
@endsection

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Administração /</span> Avisos aos Usuários
</h4>

<livewire:pages::admin.avisos.index />
@endsection
