<?php

use Livewire\Component;

new class extends Component {
  //
};
?>


<div data-bs-spy="scroll" class="scrollspy-example">

@php
$configData = Helper::appClasses();
@endphp

  <section id="hero-animation">
    <div id="landingHero" class="section-py landing-hero position-relative">
      <div class="container">
        <div class="hero-text-box text-center">
          <h1 class="text-primary hero-title display-4 fw-bold">
            O Cronograma mostra QUANDO fazer. <br>
            A DCF mostra se está PREPARADO para fazer.
          </h1>
          <h2 class="hero-sub-title h6 mb-4 pb-1">
            Muitas obras não atrasam por falta de cronograma. <br class="d-none d-lg-block" />
            Atrasam porque projetos, materiais, liberações, recursos e decisões não ficam prontos no <mark>momento necessário</mark>.
          </h2>
          <div class="landing-hero-btn d-inline-block position-relative">
            <a href="#landingPricing" class="btn btn-warning">SOLICITAR DIAGNÓSTICO</a>
            <br>
            <small class="text-muted">Sem compromisso. Apenas uma avaliação rápida.</small>
          </div>
        </div>
        <div id="heroDashboardAnimation" class="hero-animation-img">
          <a href="{{url('/app/ecommerce/dashboard')}}" target="_blank">
            <div id="heroAnimationImg" class="position-relative hero-dashboard-img">
              <img src="{{asset('assets/img/front-pages/landing-page/hero-dashboard-'.$configData['style'].'.png')}}" alt="hero dashboard" class="animation-img" data-app-light-img="front-pages/landing-page/hero-dashboard-light.png" data-app-dark-img="front-pages/landing-page/hero-dashboard-dark.png" />
              <img src="{{asset('assets/img/front-pages/landing-page/hero-elements-'.$configData['style'].'.png')}}" alt="hero elements" class="position-absolute hero-elements-img animation-img top-0 start-0" data-app-light-img="front-pages/landing-page/hero-elements-light.png" data-app-dark-img="front-pages/landing-page/hero-elements-dark.png" />
            </div>
          </a>
        </div>
      </div>
    </div>
    <div class="landing-hero-blank"></div>
  </section>

  <section id="landingFeatures" class="section-py landing-features">
    <div class="container">
      <div class="text-center mb-3 pb-1">
        <span class="badge bg-label-primary">Antecipe Gargalos</span>
      </div>
      <h3 class="text-center mb-1">
        Antecipe o que pode impedir sua obra de avançar.
      </h3>
      <p class="text-center mb-3 mb-md-5 pb-3">
        Planejamento integrado, prontidão e controle das interfaces entre Engenharia, Suprimentos e Construção — com método especializado, mentoria e software próprio.
      </p>
      <div class="features-icon-wrapper row gx-0 gy-4 g-sm-5">
        <div class="col-lg-4 col-sm-6 text-center features-icon-box">
          <div class="text-center mb-3">
            <img src="{{asset('assets/img/front-pages/icons/laptop.png')}}" alt="laptop charging" />
          </div>
          <h5 class="mb-3">Gestão de Restrições</h5>
          <p class="features-icon-description">
            Identifique e resolva impedimentos antes que eles travem o seu cronograma. Tenha clareza imediata do que precisa ser desbloqueado para liberar a próxima etapa.
          </p>
        </div>
        <div class="col-lg-4 col-sm-6 text-center features-icon-box">
          <div class="text-center mb-3">
            <img src="{{asset('assets/img/front-pages/icons/rocket.png')}}" alt="transition up" />
          </div>
          <h5 class="mb-3">Controle de Suprimentos</h5>
          <p class="features-icon-description">
            Nunca mais paralise a execução por falta de materiais. Sincronize prazos de compra e entrega diretamente com a data em que a atividade precisa começar.
          </p>
        </div>
        <div class="col-lg-4 col-sm-6 text-center features-icon-box">
          <div class="text-center mb-3">
            <img src="{{asset('assets/img/front-pages/icons/paper.png')}}" alt="edit" />
          </div>
          <h5 class="mb-3">Liberação de Engenharia</h5>
          <p class="features-icon-description">
            Garanta que escopos, plantas e documentações técnicas estejam 100% aprovados e na mão da sua equipe antes de dar o pontapé inicial na execução.
          </p>
        </div>
        <div class="col-lg-4 col-sm-6 text-center features-icon-box">
          <div class="text-center mb-3">
            <img src="{{asset('assets/img/front-pages/icons/check.png')}}" alt="3d select solid" />
          </div>
          <h5 class="mb-3">Foco na Execução</h5>
          <p class="features-icon-description">
            Transforme o planejamento estático em ação diária. Saiba exatamente quem faz o quê, quando e como, eliminando ruídos de comunicação e desculpas.
          </p>
        </div>
        <div class="col-lg-4 col-sm-6 text-center features-icon-box">
          <div class="text-center mb-3">
            <img src="{{asset('assets/img/front-pages/icons/user.png')}}" alt="lifebelt" />
          </div>
          <h5 class="mb-3">Curvas de Avanço</h5>
          <p class="features-icon-description">
            Abandone o "achômetro". Acompanhe a saúde do projeto com curvas em "S" dinâmicas e saiba instantaneamente se você está adiantado, no prazo ou precisando agir.
          </p>
        </div>
        <div class="col-lg-4 col-sm-6 text-center features-icon-box">
          <div class="text-center mb-3">
            <img src="{{asset('assets/img/front-pages/icons/keyboard.png')}}" alt="google docs" />
          </div>
          <h5 class="mb-3">Implantação e Suporte</h5>
          <p class="features-icon-description">
            Sem curvas de aprendizado frustrantes. Nossa interface intuitiva e suporte humanizado garantem que sua equipe comece a planejar do jeito certo desde o primeiro dia.
          </p>
        </div>
      </div>
    </div>
  </section>

  <section id="landingFeatures" class="section-py landing-features">
    <div class="container">
      <div class="row align-items-center justify-content-between">
        <div class="col-lg-5">
          <div class="section-title">
            <p class="text-danger text-uppercase fw-bold mb-3"><mark>O PAPEL ACEITA TUDO!</mark></p>
            <h1>Uma camada de gestão entre o cronograma e a execução.</h1>
            <div class="content mb-0 mt-4">
              <p>
                A DCF.eng não pretende substituir Primavera P6, Microsoft Project, ERP ou quaisquer outros sistemas. <br>
                Nós organizamos a rotina que conecta o planejamento formal às condições reais necessárias para executar.
              </p>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="difference-of-us-item p-3 rounded mr-0 me-lg-4">
            <div class="d-block d-sm-flex align-items-center m-2">
              <div class="me-4 mb-4 mb-sm-0">
                <div class="badge bg-label-primary p-3 rounded mb-3">
                  <i class='bx bx-check-shield fs-3'></i>
                </div>
              </div>
              <div class="block">
                <h3 class="mb-3">Planejamento integrado</h3>
                <p class="mb-0">Transforme o cronograma em uma visão coordenada das necessidades de Engenharia, Suprimentos e Construção.</p>
              </div>
            </div>
          </div>
          <div class="difference-of-us-item p-3 rounded mr-0 me-lg-4">
            <div class="d-block d-sm-flex align-items-center m-2">
              <div class="me-4 mb-4 mb-sm-0">
                <div class="badge bg-label-primary p-3 rounded mb-3">
                  <i class='bx bx-check-shield fs-3'></i>
                </div>
              </div>
              <div class="block">
                <h3 class="mb-3">Gestão da prontidão</h3>
                <p class="mb-0">Identifique antecipadamente o que precisa ser resolvido para que cada atividade possa entrar no plano de execução.</p>
              </div>
            </div>
          </div>
          <div class="difference-of-us-item p-3 rounded mr-0 me-lg-4">
            <div class="d-block d-sm-flex align-items-center m-2">
              <div class="me-4 mb-4 mb-sm-0">
                <div class="badge bg-label-primary p-3 rounded mb-3">
                  <i class='bx bx-check-shield fs-3'></i>
                </div>
              </div>
              <div class="block">
                <h3 class="mb-3">Controle das interfaces</h3>
                <p class="mb-0">Dê responsável, prazo, criticidade e rastreabilidade às restrições que atravessam diferentes áreas do projeto.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="landingReviews" class="section-py bg-body landing-reviews pb-0">
    <!-- What people say slider: Start -->
    <div class="container">
      <div class="row align-items-center gx-0 gy-4 g-lg-5">
        <div class="col-md-6 col-lg-5 col-xl-3">
          <div class="mb-3 pb-1">
            <span class="badge bg-label-primary">Real Customers Reviews</span>
          </div>
          <h3 class="mb-1"><span class="section-title">What people say</span></h3>
          <p class="mb-3 mb-md-5">
            See what our customers have to<br class="d-none d-xl-block" />
            say about their experience.
          </p>
          <div class="landing-reviews-btns d-flex align-items-center gap-3">
            <button id="reviews-previous-btn" class="btn btn-label-primary reviews-btn" type="button">
              <i class="bx bx-chevron-left bx-sm"></i>
            </button>
            <button id="reviews-next-btn" class="btn btn-label-primary reviews-btn" type="button">
              <i class="bx bx-chevron-right bx-sm"></i>
            </button>
          </div>
        </div>
        <div class="col-md-6 col-lg-7 col-xl-9">
          <div class="swiper-reviews-carousel overflow-hidden mb-5 pb-md-2 pb-md-3">
            <div class="swiper" id="swiper-reviews">
              <div class="swiper-wrapper">
                <div class="swiper-slide">
                  <div class="card h-100">
                    <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                      <div class="mb-3">
                        <img src="{{asset('assets/img/front-pages/branding/logo-1.png')}}" alt="client logo" class="client-logo img-fluid" />
                      </div>
                      <p>
                        “Frest is hands down the most useful front end Bootstrap theme I've ever used. I can't wait
                        to use it again for my next project.”
                      </p>
                      <div class="text-warning mb-3">
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                      </div>
                      <div class="d-flex align-items-center">
                        <div class="avatar me-2 avatar-sm">
                          <img src="{{asset('assets/img/avatars/1.png')}}" alt="Avatar" class="rounded-circle" />
                        </div>
                        <div>
                          <h6 class="mb-0">Cecilia Payne</h6>
                          <p class="small text-muted mb-0">CEO of Airbnb</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="swiper-slide">
                  <div class="card h-100">
                    <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                      <div class="mb-3">
                        <img src="{{asset('assets/img/front-pages/branding/logo-2.png')}}" alt="client logo" class="client-logo img-fluid" />
                      </div>
                      <p>
                        “I've never used a theme as versatile and flexible as Frest. It's my go to for building
                        dashboard sites on almost any project.”
                      </p>
                      <div class="text-warning mb-3">
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                      </div>
                      <div class="d-flex align-items-center">
                        <div class="avatar me-2 avatar-sm">
                          <img src="{{asset('assets/img/avatars/2.png')}}" alt="Avatar" class="rounded-circle" />
                        </div>
                        <div>
                          <h6 class="mb-0">Eugenia Moore</h6>
                          <p class="small text-muted mb-0">Founder of Hubspot</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="swiper-slide">
                  <div class="card h-100">
                    <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                      <div class="mb-3">
                        <img src="{{asset('assets/img/front-pages/branding/logo-3.png')}}" alt="client logo" class="client-logo img-fluid" />
                      </div>
                      <p>
                        This template is really clean & well documented. The docs are really easy to understand and
                        it's always easy to find a screenshot from their website.
                      </p>
                      <div class="text-warning mb-3">
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                      </div>
                      <div class="d-flex align-items-center">
                        <div class="avatar me-2 avatar-sm">
                          <img src="{{asset('assets/img/avatars/3.png')}}" alt="Avatar" class="rounded-circle" />
                        </div>
                        <div>
                          <h6 class="mb-0">Curtis Fletcher</h6>
                          <p class="small text-muted mb-0">Design Lead at Dribbble</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="swiper-slide">
                  <div class="card h-100">
                    <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                      <div class="mb-3">
                        <img src="{{asset('assets/img/front-pages/branding/logo-4.png')}}" alt="client logo" class="client-logo img-fluid" />
                      </div>
                      <p>
                        All the requirements for developers have been taken into consideration, so I’m able to build
                        any interface I want.
                      </p>
                      <div class="text-warning mb-3">
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bx-star bx-sm"></i>
                      </div>
                      <div class="d-flex align-items-center">
                        <div class="avatar me-2 avatar-sm">
                          <img src="{{asset('assets/img/avatars/4.png')}}" alt="Avatar" class="rounded-circle" />
                        </div>
                        <div>
                          <h6 class="mb-0">Sara Smith</h6>
                          <p class="small text-muted mb-0">Founder of Continental</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="swiper-slide">
                  <div class="card h-100">
                    <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                      <div class="mb-3">
                        <img src="{{asset('assets/img/front-pages/branding/logo-5.png')}}" alt="client logo" class="client-logo img-fluid" />
                      </div>
                      <p>
                        “I've never used a theme as versatile and flexible as Frest. It's my go to for building
                        dashboard sites on almost any project.”
                      </p>
                      <div class="text-warning mb-3">
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                      </div>
                      <div class="d-flex align-items-center">
                        <div class="avatar me-2 avatar-sm">
                          <img src="{{asset('assets/img/avatars/5.png')}}" alt="Avatar" class="rounded-circle" />
                        </div>
                        <div>
                          <h6 class="mb-0">Eugenia Moore</h6>
                          <p class="small text-muted mb-0">Founder of Hubspot</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="swiper-slide">
                  <div class="card h-100">
                    <div class="card-body text-body d-flex flex-column justify-content-between h-100">
                      <div class="mb-3">
                        <img src="{{asset('assets/img/front-pages/branding/logo-6.png')}}" alt="client logo" class="client-logo img-fluid" />
                      </div>
                      <p>
                        Lorem ipsum dolor sit amet consectetur adipisicing elit. Veniam nemo mollitia, ad eum
                        officia numquam nostrum repellendus consequuntur!
                      </p>
                      <div class="text-warning mb-3">
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bxs-star bx-sm"></i>
                        <i class="bx bx-star bx-sm"></i>
                      </div>
                      <div class="d-flex align-items-center">
                        <div class="avatar me-2 avatar-sm">
                          <img src="{{asset('assets/img/avatars/1.png')}}" alt="Avatar" class="rounded-circle" />
                        </div>
                        <div>
                          <h6 class="mb-0">Sara Smith</h6>
                          <p class="small text-muted mb-0">Founder of Continental</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="swiper-button-next"></div>
              <div class="swiper-button-prev"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- What people say slider: End -->
    <hr class="m-0" />
    <!-- Logo slider: Start -->
    <div class="container">
      <div class="swiper-logo-carousel py-4 my-lg-2">
        <div class="swiper" id="swiper-clients-logos">
          <div class="swiper-wrapper">
            <div class="swiper-slide">
              <img src="{{asset('assets/img/front-pages/branding/logo_1-'.$configData['style'].'.png')}}" alt="client logo" class="client-logo" data-app-light-img="front-pages/branding/logo_1-light.png" data-app-dark-img="front-pages/branding/logo_1-dark.png" />
            </div>
            <div class="swiper-slide">
              <img src="{{asset('assets/img/front-pages/branding/logo_2-'.$configData['style'].'.png')}}" alt="client logo" class="client-logo" data-app-light-img="front-pages/branding/logo_2-light.png" data-app-dark-img="front-pages/branding/logo_2-dark.png" />
            </div>
            <div class="swiper-slide">
              <img src="{{asset('assets/img/front-pages/branding/logo_3-'.$configData['style'].'.png')}}" alt="client logo" class="client-logo" data-app-light-img="front-pages/branding/logo_3-light.png" data-app-dark-img="front-pages/branding/logo_3-dark.png" />
            </div>
            <div class="swiper-slide">
              <img src="{{asset('assets/img/front-pages/branding/logo_4-'.$configData['style'].'.png')}}" alt="client logo" class="client-logo" data-app-light-img="front-pages/branding/logo_4-light.png" data-app-dark-img="front-pages/branding/logo_4-dark.png" />
            </div>
            <div class="swiper-slide">
              <img src="{{asset('assets/img/front-pages/branding/logo_5-'.$configData['style'].'.png')}}" alt="client logo" class="client-logo" data-app-light-img="front-pages/branding/logo_5-light.png" data-app-dark-img="front-pages/branding/logo_5-dark.png" />
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- Logo slider: End -->
  </section>

  <section id="landingTeam" class="section-py landing-team">
    <div class="container">
      <div class="text-center mb-3 pb-1">
        <span class="badge bg-label-primary">Our Great Team</span>
      </div>
      <h3 class="text-center mb-1"><span class="section-title">Supported</span> by Real People</h3>
      <p class="text-center mb-md-5 pb-3">Who is behind these great-looking interfaces?</p>
      <div class="row gy-5 mt-2">
        <div class="col-12">
          <div class="card mt-3 mt-lg-0 shadow-none">
            <div class="bg-label-primary position-relative team-image-box">
              <img src="{{asset('assets/img/front-pages/landing-page/team-member-1.png')}}" class="position-absolute card-img-position bottom-0 start-50 scaleX-n1-rtl img-fluid" alt="human image" />
            </div>
            <div class="card-body border border-label-primary border-top-0 text-center">
              <h5 class="card-title mb-0">Sophie Gilbert</h5>
              <p class="text-muted mb-0">Project Manager</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="landingPricing" class="section-py bg-body landing-pricing">
    <div class="container">
      <div class="text-center mb-3 pb-1">
        <span class="badge bg-label-primary">Nossos Planos</span>
      </div>
      <h3 class="text-center mb-1"><span class="section-title">Planos personalizados</span> para sua obra</h3>
      <p class="text-center mb-4 pb-3">
        Todos os planos incluem 40+ ferramentas e recursos avançados para impulsionar seu produto.<br />Escolha o melhor plano para atender
        suas necessidades.
      </p>
      <div class="text-center mb-5">
        <div class="position-relative d-inline-block pt-3 pt-md-0">
          <label class="switch switch-primary me-0">
            <span class="switch-label">Plano Mensal</span>
            <input type="checkbox" class="switch-input price-duration-toggler" checked />
            <span class="switch-toggle-slider">
              <span class="switch-on"></span>
              <span class="switch-off"></span>
            </span>
            <span class="switch-label">Plano Anual</span>
          </label>
          <div class="pricing-plans-item position-absolute d-flex">
            <img src="{{asset('assets/img/front-pages/icons/pricing-plans-arrow.png')}}" alt="pricing plans arrow" class="scaleX-n1-rtl" />
            <span class="fw-medium mt-2 ms-1"> 25% off</span>
          </div>
        </div>
      </div>
      <div class="row gy-4 pt-lg-3">
        <!-- Basic Plan: Start -->
        <div class="col-xl-6 col-lg-6">
          <div class="card">
            <div class="card-header">
              <div class="text-center">
                <img src="{{asset('assets/img/front-pages/icons/paper-airplane.png')}}" alt="paper airplane icon" class="mb-4 pb-2 scaleX-n1-rtl" />
                <h4 class="mb-1">Básico</h4>
                <div class="d-flex align-items-center justify-content-center">
                  <span class="price-monthly h1 text-primary fw-bold mb-0">$19</span>
                  <span class="price-yearly h1 text-primary fw-bold mb-0 d-none">$14</span>
                  <sub class="h6 text-muted mb-0 ms-1">/mo</sub>
                </div>
                <div class="position-relative pt-2">
                  <div class="price-yearly text-muted price-yearly-toggle d-none">$ 168 / year</div>
                </div>
              </div>
            </div>
            <div class="card-body">
              <ul class="list-unstyled">
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Timeline
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Basic search
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Live chat widget
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Email marketing
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Custom Forms
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Traffic analytics
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-label-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Basic Support
                  </h5>
                </li>
              </ul>
              <div class="d-grid mt-4 pt-3">
                <a href="{{url('/front-pages/payment')}}" class="btn btn-label-primary">Get Started</a>
              </div>
            </div>
          </div>
        </div>
        <!-- Basic Plan: End -->

        <!-- Favourite Plan: Start -->
        <div class="col-xl-6 col-lg-6">
          <div class="card border border-primary shadow-lg">
            <div class="card-header">
              <div class="text-center">
                <img src="{{asset('assets/img/front-pages/icons/plane.png')}}" alt="plane icon" class="mb-4 pb-2 scaleX-n1-rtl" />
                <h4 class="mb-1">Time</h4>
                <div class="d-flex align-items-center justify-content-center">
                  <span class="price-monthly h1 text-primary fw-bold mb-0">$29</span>
                  <span class="price-yearly h1 text-primary fw-bold mb-0 d-none">$22</span>
                  <sub class="h6 text-muted mb-0 ms-1">/mo</sub>
                </div>
                <div class="position-relative pt-2">
                  <div class="price-yearly text-muted price-yearly-toggle d-none">$ 264 / year</div>
                </div>
              </div>
            </div>
            <div class="card-body">
              <ul class="list-unstyled">
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Everything in basic
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Timeline with database
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Advanced search
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Marketing automation
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Advanced chatbot
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Campaign management
                  </h5>
                </li>
                <li>
                  <h5>
                    <span class="badge badge-center rounded-pill bg-primary p-0 me-2"><i class="bx bx-check bx-xs"></i></span>
                    Collaboration tools
                  </h5>
                </li>
              </ul>
              <div class="d-grid mt-4 pt-3">
                <a href="{{url('/front-pages/payment')}}" class="btn btn-primary">Get Started</a>
              </div>
            </div>
          </div>
        </div>
        <!-- Favourite Plan: End -->


      </div>
    </div>
  </section>

  <section id="landingFunFacts" class="section-py landing-fun-facts">
    <div class="container">
      <div class="row gy-3">
        <div class="col-sm-6 col-lg-3">
          <div class="card border border-label-primary shadow-none">
            <div class="card-body text-center">
              <img src="{{asset('assets/img/front-pages/icons/laptop.png')}}" alt="laptop" class="mb-2" />
              <h5 class="h2 mb-1">7.1k+</h5>
              <p class="fw-medium mb-0">
                Support Tickets<br />
                Resolved
              </p>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card border border-label-success shadow-none">
            <div class="card-body text-center">
              <img src="{{asset('assets/img/front-pages/icons/user-success.png')}}" alt="laptop" class="mb-2" />
              <h5 class="h2 mb-1">50k+</h5>
              <p class="fw-medium mb-0">
                Join creatives<br />
                community
              </p>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card border border-label-info shadow-none">
            <div class="card-body text-center">
              <img src="{{asset('assets/img/front-pages/icons/diamond-info.png')}}" alt="laptop" class="mb-2" />
              <h5 class="h2 mb-1">4.8/5</h5>
              <p class="fw-medium mb-0">
                Highly Rated<br />
                Products
              </p>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card border border-label-warning shadow-none">
            <div class="card-body text-center">
              <img src="{{asset('assets/img/front-pages/icons/check-warning.png')}}" alt="laptop" class="mb-2" />
              <h5 class="h2 mb-1">100%</h5>
              <p class="fw-medium mb-0">
                Money Back<br />
                Guarantee
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="landingFAQ" class="section-py bg-body landing-faq">
    <div class="container">
      <div class="text-center mb-3 pb-1">
        <span class="badge bg-label-primary">FAQ</span>
      </div>
      <h3 class="text-center mb-1">Perguntas <span class="section-title">frequentes</span></h3>
      <p class="text-center mb-5 pb-3">Ache respostas para perguntas comuns aqui.</p>
      <div class="row gy-5">
        <div class="col-lg-5">
          <div class="text-center">
            <img src="{{asset('assets/img/front-pages/landing-page/faq-boy-with-logos.png')}}" alt="faq boy with logos" class="faq-image" />
          </div>
        </div>
        <div class="col-lg-7">
          <div class="accordion accordion-header-primary" id="accordionExample">

            <div class="card accordion-item active">
              <h2 class="accordion-header" id="headingOne">
                <button type="button" class="accordion-button" data-bs-toggle="collapse" data-bs-target="#accordionOne" aria-expanded="true" aria-controls="accordionOne">
                  A DCF.eng substitui o Primavera P6 ou o Microsoft Project?
                </button>
              </h2>
              <div id="accordionOne" class="accordion-collapse collapse show" data-bs-parent="#accordionExample">
                <div class="accordion-body">
                  <strong>Não!</strong> O cronograma continua sendo a referência temporal do projeto.  <br>
                  A DCF.eng complementa essa estrutura, organizando as restrições, a prontidão e as interfaces necessárias para que as atividades possam ser executadas.
                </div>
              </div>
            </div>

            <div class="card accordion-item">
              <h2 class="accordion-header" id="headingTwo">
                <button type="button" class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#accordionTwo" aria-expanded="false" aria-controls="accordionTwo">
                  A DCF.eng atualiza diariamente as informações da obra?
                </button>
              </h2>
              <div id="accordionTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#accordionExample">
                <div class="accordion-body">
                  <strong>Não!</strong> A atualização operacional é responsabilidade da equipe da obra. <br>
                  Durante a implantação, capacitamos os usuários e estruturamos a rotina para que as informações sejam registradas de forma consistente.
                </div>
              </div>
            </div>

            <div class="card accordion-item">
              <h2 class="accordion-header" id="headingThree">
                <button type="button" class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#accordionThree" aria-expanded="false" aria-controls="accordionThree">
                  A consultoria substitui o engenheiro e/ou técnico de planejamento da minha obra?
                </button>
              </h2>
              <div id="accordionThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#accordionExample">
                <div class="accordion-body">
                  <strong>Não!</strong> Nosso serviço é complementar. Atuamos no planejamento e controle de obras, melhorando os processos e padronizando as informações das suas obras.
                  Sempre trabalhamos em conjunto com a equipe de execução e demais profissionais já envolvidos na obra.
                </div>
              </div>
            </div>

            <div class="card accordion-item">
              <h2 class="accordion-header" id="headingFour">
                <button type="button" class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#accordionFour" aria-expanded="false" aria-controls="accordionFour">
                  A implantação precisa ser presencial?
                </button>
              </h2>
              <div id="accordionFour" class="accordion-collapse collapse" aria-labelledby="headingFour" data-bs-parent="#accordionExample">
                <div class="accordion-body">
                  <strong>Não necessariamente.</strong> O processo foi concebido para funcionar de forma predominantemente digital, com reuniões remotas, capacitação, acompanhamento e suporte. <br>
                  Atividades presenciais podem ser contratadas quando fizerem sentido para o projeto.
                </div>
              </div>
            </div>

            <div class="card accordion-item">
              <h2 class="accordion-header" id="headingFive">
                <button type="button" class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#accordionSeis" aria-expanded="false" aria-controls="accordionFive">
                  O serviço é software ou consultoria?
                </button>
              </h2>
              <div id="accordionSeis" class="accordion-collapse collapse" aria-labelledby="headingFive" data-bs-parent="#accordionExample">
                <div class="accordion-body">
                  <mark>É uma solução híbrida.</mark> A plataforma organiza o processo e preserva o histórico; a implantação e a mentoria ajudam a equipe a utilizar o método corretamente e conquistar autonomia.
                </div>
              </div>
            </div>

            <div class="card accordion-item">
              <h2 class="accordion-header" id="headingFive">
                <button type="button" class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#accordionSete" aria-expanded="false" aria-controls="accordionFive">
                  A DCF garante o cumprimento dos prazos da obra?
                </button>
              </h2>
              <div id="accordionSete" class="accordion-collapse collapse" aria-labelledby="headingFive" data-bs-parent="#accordionExample">
                <div class="accordion-body">
                  <strong>Não!!</strong> As decisões, informações e ações permanecem sob responsabilidade dos gestores e executores do projeto. <br>
                  A solução aumenta a visibilidade, a disciplina e a capacidade de antecipação, apoiando melhores decisões.
                </div>
              </div>
            </div>

            <div class="card accordion-item">
              <h2 class="accordion-header" id="headingFive">
                <button type="button" class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#accordionOito" aria-expanded="false" aria-controls="accordionFive">
                  Quanto tempo leva a implantação?
                </button>
              </h2>
              <div id="accordionOito" class="accordion-collapse collapse" aria-labelledby="headingFive" data-bs-parent="#accordionExample">
                <div class="accordion-body">
                  O prazo depende da complexidade da obra, da qualidade dos dados disponíveis e da disponibilidade da equipe. <br>
                  Essas variáveis são avaliadas no diagnóstico inicial e consideradas na proposta comercial.
                </div>
              </div>
            </div>


          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="landingCTA" class="section-py landing-cta p-lg-0 pb-0">
    <div class="container">
      <div class="row align-items-center gy-5 gy-lg-0">
        <div class="col-lg-6 text-center text-lg-start">
          <h6 class="h2 text-primary fw-bold mb-1">Pronto(a) para iniciar?</h6>
          <p class="fw-medium mb-4">Faça seu diagnóstico gratuito e entenda como a DCF pode ajudar sua obra.</p>
          <a href="#" class="btn btn-warning">SOLICITAR DIAGNÓSTICO</a>
        </div>
        <div class="col-lg-6 pt-lg-5 text-center text-lg-end">
          <img src="{{asset('assets/img/front-pages/landing-page/cta-dashboard.png')}}" alt="cta dashboard" class="img-fluid" />
        </div>
      </div>
    </div>
  </section>

  <section id="landingContact" class="section-py bg-body landing-contact">
    <div class="container">
      <div class="text-center mb-3 pb-1">
        <span class="badge bg-label-primary">Contact US</span>
      </div>
      <h3 class="text-center mb-1"><span class="section-title">Let's work</span> together</h3>
      <p class="text-center mb-4 mb-lg-5 pb-md-3">Any question or remark? just write us a message</p>
      <div class="row gy-4">
        <div class="col-lg-5">
          <div class="contact-img-box position-relative border p-2 h-100">
            <img src="{{asset('assets/img/front-pages/landing-page/contact-customer-service.png')}}" alt="contact customer service" class="contact-img w-100 scaleX-n1-rtl img-fluid" />
            <div class="pt-3 px-4 pb-1">
              <div class="row gy-3 gx-md-4">
                <div class="col-md-6 col-lg-12 col-xl-6">
                  <div class="d-flex align-items-center">
                    <div class="badge bg-label-primary rounded p-2 me-2"><i class="bx bx-envelope bx-sm"></i></div>
                    <div>
                      <p class="mb-0">Email</p>
                      <h5 class="mb-0">
                        <a href="mailto:example@gmail.com" class="text-heading">example@gmail.com</a>
                      </h5>
                    </div>
                  </div>
                </div>
                <div class="col-md-6 col-lg-12 col-xl-6">
                  <div class="d-flex align-items-center">
                    <div class="badge bg-label-success rounded p-2 me-2">
                      <i class="bx bx-phone-call bx-sm"></i>
                    </div>
                    <div>
                      <p class="mb-0">Phone</p>
                      <h5 class="mb-0"><a href="tel:+1234-568-963" class="text-heading">+1234 568 963</a></h5>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="card">
            <div class="card-body">
              <h4 class="mb-1">Send a message</h4>
              <p class="mb-4">
                If you would like to discuss anything related to payment, account, licensing,<br class="d-none d-lg-block" />
                partnerships, or have pre-sales questions, you’re at the right place.
              </p>
              <form>
                <div class="row g-4">
                  <div class="col-md-6">
                    <label class="form-label" for="contact-form-fullname">Full Name</label>
                    <input type="text" class="form-control" id="contact-form-fullname" placeholder="john" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="contact-form-email">Email</label>
                    <input type="text" id="contact-form-email" class="form-control" placeholder="johndoe@gmail.com" />
                  </div>
                  <div class="col-12">
                    <label class="form-label" for="contact-form-message">Message</label>
                    <textarea id="contact-form-message" class="form-control" rows="9" placeholder="Write a message"></textarea>
                  </div>
                  <div class="col-12">
                    <button type="submit" class="btn btn-primary">Send inquiry</button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

</div>
