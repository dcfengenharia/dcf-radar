@isset($pageConfigs)
{!! Helper::updatePageConfig($pageConfigs) !!}
@endisset
@php
$configData = Helper::appClasses();
@endphp
@extends('layouts/commonMaster')

@php
/* Display elements */
$contentNavbar = ($contentNavbar ?? true);
$containerNav = ($containerNav ?? 'container-xxl');
$isNavbar = ($isNavbar ?? true);
$isMenu = ($isMenu ?? true);
$isFlex = ($isFlex ?? false);
$isFooter = ($isFooter ?? true);
$customizerHidden = ($customizerHidden ?? '');

/* HTML Classes */
$menuFixed = (isset($configData['menuFixed']) ? $configData['menuFixed'] : '');
if(isset($navbarType)) {
  $configData['navbarType'] = $navbarType;
}
$navbarType = (isset($configData['navbarType']) ? $configData['navbarType'] : '');
$footerFixed = (isset($configData['footerFixed']) ? $configData['footerFixed'] : '');
$menuCollapsed = (isset($configData['menuCollapsed']) ? $configData['menuCollapsed'] : '');

/* Content classes */
$container = ($configData['contentLayout'] === 'compact') ? 'container-xxl' : 'container-fluid';

@endphp

@section('layoutContent')
<div class="layout-wrapper layout-content-navbar {{ $isMenu ? '' : 'layout-without-menu' }}">
  <div class="layout-container">

    @if ($isMenu)
    @persist('sidebar')
    @include('layouts/sections/menu/adminVerticalMenu')
    @endpersist
    @endif

    <!-- Layout page -->
    <div class="layout-page">

      <x-banner />
      <x-modal-acesso-negado />

      {{-- Aviso de "entrar como tenant" ativo — o próprio admin pode estar
           impersonando alguém enquanto navega dentro da área exclusiva. --}}
      <x-impersonation-banner />

      {{-- Popup de avisos importantes da plataforma (gerenciados aqui mesmo,
           em Avisos aos Usuários) — mesmo @persist do contentNavbarLayout:
           bootstrap.Modal real, auto-abre no mount. --}}
      @persist('avisos-plataforma-popup')
      <livewire:avisos-plataforma.popup />
      @endpersist

      <!-- BEGIN: Navbar-->
      @if ($isNavbar)
      @persist('topnav')
      @include('layouts/sections/navbar/navbar', ['hideEmpresaSwitcher' => true])
      @endpersist
      @endif
      <!-- END: Navbar-->

      <!-- Content wrapper -->
      <div class="content-wrapper">

        <!-- Content -->
        @if ($isFlex)
        <div class="{{$container}} d-flex align-items-stretch flex-grow-1 p-0">
          @else
          <div class="{{$container}} flex-grow-1 container-p-y">
            @endif

            @yield('content')

          </div>
          <!-- / Content -->

          <!-- Footer -->
          @if ($isFooter)
          @include('layouts/sections/footer/footer')
          @endif
          <!-- / Footer -->
          <div class="content-backdrop fade"></div>
        </div>
        <!--/ Content wrapper -->
      </div>
      <!-- / Layout page -->
    </div>

    @persist('radar-loading')
    <x-radar-loading />
    @endpersist

    @if ($isMenu)
    <!-- Overlay -->
    <div class="layout-overlay layout-menu-toggle"></div>
    @endif
    <!-- Drag Target Area To SlideIn Menu On Small Screens -->
    <div class="drag-target"></div>
  </div>
  <!-- / Layout wrapper -->
  @endsection
