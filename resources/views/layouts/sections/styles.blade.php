<!-- BEGIN: Theme CSS-->
<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&family=Rubik:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="{{ asset(mix('assets/vendor/fonts/boxicons.css')) }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/fonts/fontawesome.css')) }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/fonts/flag-icons.css')) }}" />
<!-- Core CSS -->
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/css' .$configData['rtlSupport'] .'/core.css')) }}" class="{{ $configData['hasCustomizer'] ? 'template-customizer-core-css' : '' }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/css' .$configData['rtlSupport'] .'/' .$configData['theme'].'.css')) }}" class="{{ $configData['hasCustomizer'] ? 'template-customizer-theme-css' : '' }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/css/demo.css')) }}" />
<!-- Vendors CSS -->
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css')) }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/libs/typeahead-js/typeahead.css')) }}" />
{{-- toastr carregado globalmente aqui (não só nas páginas que o incluem
     individualmente) — vários componentes em toda a aplicação disparam
     toastr.success(...)/error(...) via wire:navigate, então precisa
     estar disponível em QUALQUER página, não só nas que lembraram de
     incluir o vendor script/style manualmente. --}}
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/libs/toastr/toastr.css')) }}" />

<!-- Vendor Styles -->
@yield('vendor-style')


<!-- Page Styles -->
@yield('page-style')

@livewireStyles
