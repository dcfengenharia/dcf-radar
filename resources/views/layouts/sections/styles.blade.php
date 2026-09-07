<!-- BEGIN: Theme CSS-->
{{-- Fonts — hospedadas localmente (Pré-produção, Etapa 2, seção 5):
     IBM Plex Sans + Rubik nunca mais carregadas ao vivo de
     fonts.googleapis.com/fonts.gstatic.com — ver resources/assets/vendor/
     fonts/google-fonts.scss pra origem/licença dos arquivos. --}}
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/fonts/google-fonts.css')) }}" />

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
