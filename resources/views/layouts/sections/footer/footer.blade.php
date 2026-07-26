@php
$containerFooter = ($configData['contentLayout'] === 'compact') ? 'container-xxl' : 'container-fluid';
@endphp

<!-- Footer-->
<footer class="content-footer footer bg-footer-theme">
  <div class="{{ $containerFooter }} d-flex flex-wrap justify-content-between py-2 flex-md-row flex-column">
    <div class="mb-2 mb-md-0">
      © {{ date('Y') }}. Desenvolvido com 🍺 por <a href="{{ (!empty(config('variables.creatorUrl')) ? config('variables.creatorUrl') : '') }}" target="_blank" class="footer-link fw-medium">{{ (!empty(config('variables.creatorName')) ? config('variables.creatorName') : '') }}</a>. Todos os direitos reservados.
    </div>
    <div  class="d-none d-lg-inline-block">
      <a href="javascript:void(0)" onclick="Livewire.dispatch('abrir-suporte', { url: window.location.href })" class="footer-link d-none d-sm-inline-block me-4">Suporte</a>
      <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="btn btn-sm btn-outline-danger">
        <i class='bx bx-log-out-circle me-1'></i>
        <span class="align-middle">Sair</span>
      </a>
    </div>
  </div>
</footer>
<!--/ Footer-->
