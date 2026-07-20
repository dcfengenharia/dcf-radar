@extends('layouts.layoutMaster')
@php
  $configData = Helper::appClasses();
@endphp
@section('title', 'Academy Dashboard - Apps')

@section('vendor-style')
<link rel="stylesheet" href="{{asset('assets/vendor/libs/datatables-bs5/datatables.bootstrap5.css')}}" />
<link rel="stylesheet" href="{{asset('assets/vendor/libs/datatables-responsive-bs5/responsive.bootstrap5.css')}}" />
<link rel="stylesheet" href="{{asset('assets/vendor/libs/datatables-buttons-bs5/buttons.bootstrap5.css')}}" />
<link rel="stylesheet" href="{{asset('assets/vendor/libs/apex-charts/apex-charts.css')}}" />
@endsection

@section('vendor-script')
<script src="{{asset('assets/vendor/libs/moment/moment.js')}}"></script>
<script src="{{asset('assets/vendor/libs/datatables-bs5/datatables-bootstrap5.js')}}"></script>
<script src="{{asset('assets/vendor/libs/apex-charts/apexcharts.js')}}"></script>
@endsection

@section('page-script')
<script src="{{asset('assets/js/app-academy-dashboard.js')}}"></script>
@endsection

@section('content')
<!-- Hour chart  -->
<div class="card bg-transparent border-0 my-4 shadow-none">
  <div class="card-body row p-0 pb-3">
    <div class="col-12 col-md-8 card-separator">
      <h3>Fala comigo bb {{ Auth::user()->first_name }} 👋🏻 </h3>
      <div class="col-12 col-lg-7">
        <p>Your progress this week is Awesome. let's keep it up and get a lot of points reward !</p>
      </div>
      <div class="d-flex justify-content-between flex-wrap gap-3 me-5">
        <div class="d-flex align-items-center gap-3 me-4 me-sm-0">
          <span class=" bg-label-primary p-2 rounded">
            <i class='bx bx-laptop bx-sm'></i>
          </span>
          <div class="content-right">
            <p class="mb-0">Minhas Empresas</p>
            <h4 class="text-primary mb-0">1</h4>
          </div>
        </div>
        <div class="d-flex align-items-center gap-3">
          <span class="bg-label-info p-2 rounded">
            <i class='bx bx-bulb bx-sm'></i>
          </span>
          <div class="content-right">
            <p class="mb-0">Minhas Obras</p>
            <h4 class="text-info mb-0">3</h4>
          </div>
        </div>
        <div class="d-flex align-items-center gap-3">
          <span class="bg-label-warning p-2 rounded">
            <i class='bx bx-check-circle bx-sm'></i>
          </span>
          <div class="content-right">
            <p class="mb-0">Course Completed </p>
            <h4 class="text-warning mb-0">14</h4>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-4 ps-md-3 ps-lg-5 pt-3 pt-md-0">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div>
            <h5 class="mb-2">Time Spendings</h5>
            <p class="mb-4">Weekly report</p>
          </div>
          <div class="time-spending-chart">
            <h3 class="mb-2">231<span class="text-muted">h</span> 14<span class="text-muted">m</span> </h3>
            <span class="badge bg-label-success">+18.4%</span>
          </div>
        </div>
        <div id="leadsReportChart"></div>
      </div>
    </div>
  </div>
</div>
<!-- Hour chart End  -->
<!-- Card Border Shadow -->
<div class="row">
  <div class="col-sm-6 col-lg-3 mb-4">
    <div class="card card-border-shadow-primary h-100">
      <div class="card-body">
        <div class="d-flex align-items-center mb-2 pb-1">
          <div class="avatar me-2">
            <span class="avatar-initial rounded bg-label-primary"><i class="bx bxs-truck"></i></span>
          </div>
          <h4 class="ms-1 mb-0">42</h4>
        </div>
        <p class="mb-1">On route vehicles</p>
        <p class="mb-0">
          <span class="fw-medium me-1">+18.2%</span>
          <small class="text-muted">than last week</small>
        </p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3 mb-4">
    <div class="card card-border-shadow-warning h-100">
      <div class="card-body">
        <div class="d-flex align-items-center mb-2 pb-1">
          <div class="avatar me-2">
            <span class="avatar-initial rounded bg-label-warning"><i class='bx bx-error'></i></span>
          </div>
          <h4 class="ms-1 mb-0">8</h4>
        </div>
        <p class="mb-1">Vehicles with errors</p>
        <p class="mb-0">
          <span class="fw-medium me-1">-8.7%</span>
          <small class="text-muted">than last week</small>
        </p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3 mb-4">
    <div class="card card-border-shadow-danger h-100">
      <div class="card-body">
        <div class="d-flex align-items-center mb-2 pb-1">
          <div class="avatar me-2">
            <span class="avatar-initial rounded bg-label-danger"><i class='bx bx-git-repo-forked'></i></span>
          </div>
          <h4 class="ms-1 mb-0">27</h4>
        </div>
        <p class="mb-1">Deviated from route</p>
        <p class="mb-0">
          <span class="fw-medium me-1">+4.3%</span>
          <small class="text-muted">than last week</small>
        </p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3 mb-4">
    <div class="card card-border-shadow-info h-100">
      <div class="card-body">
        <div class="d-flex align-items-center mb-2 pb-1">
          <div class="avatar me-2">
            <span class="avatar-initial rounded bg-label-info"><i class='bx bx-time-five'></i></span>
          </div>
          <h4 class="ms-1 mb-0">13</h4>
        </div>
        <p class="mb-1">Late vehicles</p>
        <p class="mb-0">
          <span class="fw-medium me-1">-2.5%</span>
          <small class="text-muted">than last week</small>
        </p>
      </div>
    </div>
  </div>
</div>
<!--/ Card Border Shadow -->
@endsection
