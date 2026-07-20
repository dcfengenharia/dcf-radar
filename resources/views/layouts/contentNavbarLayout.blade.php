@isset($pageConfigs)
{!! Helper::updatePageConfig($pageConfigs) !!}
@endisset
@php
$configData = Helper::appClasses();
@endphp
@extends('layouts/commonMaster' )

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
    @include('layouts/sections/menu/verticalMenu')
    @endpersist
    @endif


    <!-- Layout page -->
    <div class="layout-page">

      {{-- Below commented code read by artisan command while installing jetstream. !! Do not remove if you want to use jetstream. --}}
      <x-banner />
      <x-modal-acesso-negado />

      {{-- Aviso de configuração inicial pendente (edite em app/Support/Onboarding/OnboardingChecklist.php) --}}
      <x-onboarding-alert />

      {{-- Popup de boas-vindas, uma vez por login, enquanto houver cadastro obrigatório pendente --}}
      <x-onboarding-popup />

      {{-- Aviso de "entrar como tenant" ativo (dono da plataforma) --}}
      <x-impersonation-banner />

      {{-- Modal de criar nova empresa — acionado pela navbar ou pela página Dados da Empresa.
           @persist (mesmo motivo do radar-loading logo abaixo): esse componente usa
           @script + bootstrap.Modal real (backdrop injetado no <body> em runtime, fora
           da árvore rastreada pelo Livewire); sem @persist ele é destruído e remontado
           a cada wire:navigate, arriscando o mesmo travamento de navbar já visto e
           corrigido no radar-loading (ver feedback_persist_componentes_globais_livewire). --}}
      @persist('empresa-create-modal')
      <livewire:empresas.create />
      @endpersist

      {{-- Popup de avisos importantes da plataforma (gerenciados em /admin/avisos) —
           mesmo motivo do @persist acima: bootstrap.Modal real, auto-abre no mount. --}}
      @persist('avisos-plataforma-popup')
      <livewire:avisos-plataforma.popup />
      @endpersist

      <!-- BEGIN: Navbar-->
      @if ($isNavbar)
      @persist('topnav')
      @include('layouts/sections/navbar/navbar')
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

    {{-- Loading global (edite em resources/views/components/radar-loading.blade.php) --}}
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
