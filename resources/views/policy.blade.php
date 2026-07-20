@php
$configData = Helper::appClasses();
@endphp

@extends('layouts.layoutFront')

@section('title', 'Política de Privacidade')

@section('content')
<div class="container section-py" style="max-width: 860px;">
    <div class="policy-content">
        {!! $policy !!}
    </div>
</div>
@endsection
