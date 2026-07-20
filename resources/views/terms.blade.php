@php
$configData = Helper::appClasses();
@endphp

@extends('layouts.layoutFront')

@section('title', 'Termos de Uso')

@section('content')
<div class="container section-py" style="max-width: 860px;">
    <div class="policy-content">
        {!! $terms !!}
    </div>
</div>
@endsection
