@php
$configData = Helper::appClasses();

// Reaproveita o MESMO menuData compartilhado por MenuServiceProvider — só
// filtra pelos itens gateados por 'acessar-admin-plataforma' (o cabeçalho
// "ADMINISTRAÇÃO" e o link /admin com seu submenu), achatando o submenu
// em links de primeiro nível já que não sobra mais nada do app do tenant
// pra agrupar aqui dentro. Adicionar uma página nova em /admin só exige
// editar resources/menu/verticalMenu.json — nada aqui precisa mudar.
$itemAdmin = collect($menuData[0]->menu)->first(
    fn ($menu) => ($menu->gate ?? null) === 'acessar-admin-plataforma' && isset($menu->submenu)
);
$linksAdmin = $itemAdmin ? array_merge(
    [(object) ['url' => $itemAdmin->url, 'name' => $itemAdmin->name, 'icon' => $itemAdmin->icon, 'slug' => $itemAdmin->slug]],
    $itemAdmin->submenu
) : [];
$currentRouteName = Route::currentRouteName();
@endphp

<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">

  <div class="app-brand demo">
    <a href="{{ route('admin.dashboard') }}" class="app-brand-link">
      <img src="{{ asset('assets/img/logos/logo_oficial.png') }}" alt="" class="w-px-150 h-auto">
    </a>

    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
      <i class="bx menu-toggle-icon d-none d-xl-block fs-4 align-middle"></i>
      <i class="bx bx-x d-block d-xl-none bx-sm align-middle"></i>
    </a>
  </div>

  <div class="menu-divider mt-0"></div>
  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1">
    <li class="menu-header small fw-medium">
      <span class="menu-header-text">Administração da Plataforma</span>
    </li>

    @foreach ($linksAdmin as $link)
      <li class="menu-item {{ $currentRouteName === $link->slug ? 'active' : '' }}">
        <a href="{{ url($link->url) }}" class="menu-link" wire:navigate>
          @isset($link->icon)
            <i class="{{ $link->icon }}"></i>
          @endisset
          <div class="text-truncate">{{ $link->name }}</div>
        </a>
      </li>
    @endforeach
  </ul>

</aside>
