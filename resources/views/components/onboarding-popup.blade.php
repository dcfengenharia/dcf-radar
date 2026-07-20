{{--
    Popup de boas-vindas com o checklist de configuração inicial —
    aparece uma vez a cada login (nunca em navegações internas via
    wire:navigate) enquanto houver cadastro obrigatório pendente no
    tenant/obra atual. A flag de sessão é setada no evento Login
    (AppServiceProvider::boot()) e consumida (session()->pull()) aqui, uma
    única vez por login: como o login sempre é um POST/redirect de página
    cheia (nunca wire:navigate), DOMContentLoaded sozinho já é suficiente
    pra abrir o modal — não precisa do listener de livewire:navigating que
    outros componentes globais do layout usam.
--}}
@php
    use App\Support\Onboarding\OnboardingChecklist;

    $pendenciasAtuais = OnboardingChecklist::pendenciasAtuais();
    $mostrarPopup = session()->pull('mostrar_popup_onboarding', false) && $pendenciasAtuais !== [];
@endphp

@if ($mostrarPopup)
<div class="modal fade" id="modalOnboarding" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content p-2">
            <div class="modal-header border-0">
                <h5 class="modal-title">👋 Bem-vindo(a) ao DCF Radar!</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body pt-0">
                <p class="text-muted mb-3">
                    Antes de começar a usar o Radar no dia a dia, complete estes
                    cadastros básicos:
                </p>
                <ul class="list-group list-group-flush mb-3">
                    @foreach ($pendenciasAtuais as $passo)
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <div>
                            <i class='bx bx-radio-circle text-warning me-1'></i>
                            <strong>{{ $passo->titulo }}</strong>
                            <div class="text-muted small ms-4">{{ $passo->descricao }}</div>
                        </div>
                        <a href="{{ route($passo->rotaAcao) }}" class="btn btn-sm btn-outline-primary">{{ $passo->rotuloAcao }}</a>
                    </li>
                    @endforeach
                </ul>
                <div class="d-flex justify-content-between align-items-center">
                    <a href="{{ route('app.onboarding') }}" class="small">Ver checklist completo</a>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi, vou começar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('modalOnboarding');
        if (el && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(el).show();
        }
    });
</script>
@endif
