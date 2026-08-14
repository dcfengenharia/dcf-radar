@extends('layouts/layoutMaster')

@section('title', 'Importação — ' . ($importacao->arquivo ?? $importacao->obra->name))

@section('content')
<h4 class="py-3 breadcrumb-wrapper mb-2">
  <span class="text-muted fw-light">Radar / <a href="{{ route('radar.cronograma') }}">Cronograma</a> /</span> {{ $importacao->importado_em->format('d/m/Y H:i') }}
</h4>

<livewire:pages::radar.importacao-detalhe :importacao="$importacao" />
@endsection
