@php
$configData = Helper::appClasses();
@endphp

<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">

  <!-- ! Hide app brand if navbar-full -->
  @if(!isset($navbarFull))
  <div class="app-brand demo">
    <a href="{{url('/')}}" class="app-brand-link">
      <img src="{{ asset('assets/img/logos/logo_oficial.png') }}" alt="" class="w-px-150 h-auto">
    </a>

    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
      <i class="bx menu-toggle-icon d-none d-xl-block fs-4 align-middle"></i>
      <i class="bx bx-x d-block d-xl-none bx-sm align-middle"></i>
    </a>
  </div>
  @endif

  <!-- ! Hide menu divider if navbar-full -->
  @if(!isset($navbarFull))
  <div class="menu-divider mt-0 ">
  </div>
  @endif

  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1">
    @foreach ($menuData[0]->menu as $menu)

    @continue(isset($menu->gate) && !\Illuminate\Support\Facades\Gate::allows($menu->gate))

    @continue(isset($menu->funcionalidade) && !\App\Support\CatalogoFuncionalidades::usuarioPodeVer($menu->funcionalidade))

    @continue(isset($menu->submenu) && !\App\Support\CatalogoFuncionalidades::algumSubitemVisivel($menu->submenu))

    {{-- adding active and open class if child is active --}}

    {{-- menu headers --}}
    @if (isset($menu->menuHeader))
    <li class="menu-header small fw-medium">
      <span class="menu-header-text">{{ __($menu->menuHeader) }}</span>
    </li>

    @else

    {{-- active menu method --}}
    @php
    $activeClass = null;
    $currentRouteName = Route::currentRouteName();

    if ($currentRouteName === $menu->slug) {
    $activeClass = 'active';
    }
    elseif (isset($menu->submenu)) {
    if (gettype($menu->slug) === 'array') {
    foreach($menu->slug as $slug){
    if (str_contains($currentRouteName,$slug) and strpos($currentRouteName,$slug) === 0) {
    $activeClass = 'active open';
    }
    }
    }
    else{
    if (str_contains($currentRouteName,$menu->slug) and strpos($currentRouteName,$menu->slug) === 0) {
    $activeClass = 'active open';
    }
    }

    }
    @endphp

    {{-- main menu --}}
    <li class="menu-item {{$activeClass}}">
      <a href="{{ isset($menu->url) ? url($menu->url) : 'javascript:void(0);' }}" class="{{ isset($menu->submenu) ? 'menu-link menu-toggle' : 'menu-link' }}" @if(!isset($menu->submenu) && isset($menu->url)) wire:navigate @endif @if (isset($menu->target) and !empty($menu->target)) target="_blank" @endif>
        @isset($menu->icon)
        <i class="{{ $menu->icon }}"></i>
        @endisset
        <div class="text-truncate">{{ isset($menu->name) ? __($menu->name) : '' }}</div>
        @isset($menu->badge)
          <div class="badge bg-{{ $menu->badge[0] }} rounded-pill ms-auto">{{ $menu->badge[1] }}</div>
        @endisset
      </a>

      {{-- submenu --}}
      @isset($menu->submenu)
      @include('layouts.sections.menu.submenu',['menu' => $menu->submenu])
      @endisset
    </li>
    @endif
    @endforeach
  </ul>

  @php $pendenciasMenu = \App\Support\Onboarding\OnboardingChecklist::pendenciasAtuais(); @endphp
  @if ($pendenciasMenu !== [])
    <div class="px-3 mb-3 mt-auto">
      <a href="{{ route('app.onboarding') }}" class="btn btn-danger w-100 d-flex align-items-center justify-content-center gap-1">
        <i class="bx bx-error-circle"></i> Configuração Pendente
      </a>
    </div>
  @endif

</aside>
