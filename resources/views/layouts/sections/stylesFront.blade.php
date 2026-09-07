<!-- BEGIN: Theme CSS-->
{{-- Fonts — hospedadas localmente (Pré-produção, Etapa 2, seção 5):
     IBM Plex Sans + Rubik nunca mais carregadas ao vivo de
     fonts.googleapis.com/fonts.gstatic.com, inclusive nesta página
     PÚBLICA (login/registro, sem sessão) — ver resources/assets/vendor/
     fonts/google-fonts.scss pra origem/licença dos arquivos. --}}
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/fonts/google-fonts.css')) }}" />

<link rel="stylesheet" href="{{ asset(mix('assets/vendor/fonts/boxicons.css')) }}" />
<!-- Core CSS -->
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/css' .$configData['rtlSupport'] .'/core.css')) }}" class="{{ $configData['hasCustomizer'] ? 'template-customizer-core-css' : '' }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/css' .$configData['rtlSupport'] .'/' .$configData['theme'].'.css')) }}" class="{{ $configData['hasCustomizer'] ? 'template-customizer-theme-css' : '' }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/css/demo.css')) }}" />
<link rel="stylesheet" href="{{ asset(mix('assets/vendor/css/pages/front-page.css')) }}" />
<!-- Vendor Styles -->
@yield('vendor-style')


<!-- Page Styles -->
@yield('page-style')
