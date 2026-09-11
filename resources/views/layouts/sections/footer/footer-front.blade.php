<!-- Footer: Start -->
<footer class="landing-footer bg-body footer-text">

  <div class="footer-top">
    <div class="container">
      <div class="row gx-0 gy-4 g-md-5">

        <div class="col-lg-6 col-md-4 col-sm-4">
          <img src="{{ asset('assets/img/logos/logo_branco.png') }}" alt="DCF Logo" class="footer-logo mb-4"  style="height: 50px;"/>
          <p class="footer-text footer-logo-description mb-4">
            Planejamento integrado, prontidão e controle para obras de maior complexidade.
          </p>
          <div>
            <button href="#landingPricing" class="btn btn-warning">SOLICITAR DIAGNÓSTICO</button>
          </div>
        </div>

        <div class="col-lg-3 col-md-4 col-sm-4">
          <h6 class="footer-title mb-4">Site</h6>
          <ul class="list-unstyled mb-md-0">
            <li class="mb-3">
              <a href="javascript:;" target="_blank" class="footer-link">Método DCF</a>
            </li>
            <li class="mb-3">
              <a href="javascript:;" target="_blank" class="footer-link">Plataforma</a>
            </li>
            <li class="mb-3">
              <a href="javascript:;" target="_blank" class="footer-link">Conteúdos</a>
            </li>
            <li class="mb-3">
              <a href="javascript:;" class="footer-link">Tutoriais</a>
            </li>
          </ul>
        </div>

        <div class="col-lg-3 col-md-4 col-sm-4">
          <h6 class="footer-title mb-4">Institucional</h6>
          <ul class="list-unstyled mb-md-0">
            <li class="mb-3">
              <a href="javascript:;" class="footer-link">Termos de Uso</a>
            </li>
            <li class="mb-3">
              <a href="javascript:;" class="footer-link">Política de Privacidade<span class="badge rounded bg-primary ms-2 px-2">New</span></a>
            </li>
            <li>
              <a href="javascript:;" target="_blank" class="footer-link">Login</a>
            </li>
          </ul>
        </div>

      </div>
    </div>
  </div>

  <div class="footer-bottom py-3">
    <div class="container d-flex flex-wrap justify-content-between flex-md-row flex-column text-center text-md-start">
      <div class="mb-2 mb-md-0">
        <span class="footer-text">© {{ date('Y') }}</span>
        <a href="javascript:;" target="_blank" class="fw-medium text-white footer-link">{{config('variables.creatorName')}}.</a>
        <span class="footer-text"> Desenvolvido com 🍺 por <strong>DCF.eng</strong> - Planejamento & Controle de Obras</span>
      </div>
      <div>
        <a href="javascript:;" class="footer-link me-3" target="_blank">
          <img src="{{asset('assets/img/front-pages/icons/twitter-light.png')}}" alt="twitter icon" data-app-light-img="front-pages/icons/twitter-light.png" data-app-dark-img="front-pages/icons/twitter-dark.png" />
        </a>
        <a href="javascript:;" class="footer-link" target="_blank">
          <img src="{{asset('assets/img/front-pages/icons/instagram-light.png')}}" alt="google icon" data-app-light-img="front-pages/icons/instagram-light.png" data-app-dark-img="front-pages/icons/instagram-dark.png" />
        </a>
      </div>
    </div>
  </div>
</footer>
<!-- Footer: End -->
