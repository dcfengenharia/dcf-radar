@php
    use App\Support\Onboarding\OnboardingChecklist;

    $pendenciasAtuais = OnboardingChecklist::pendenciasAtuais();
@endphp

@if ($pendenciasAtuais !== [])
<div class="alert alert-warning d-flex align-items-center gap-2 mb-0 rounded-0 py-2" role="alert">
    <i class='bx bx-info-circle'></i>
    <span>
        {{ count($pendenciasAtuais) === 1 ? 'Falta 1 passo de configuração' : 'Faltam '.count($pendenciasAtuais).' passos de configuração' }}
        para liberar todas as funcionalidades.
    </span>
    <a href="{{ route('app.onboarding') }}" class="alert-link">Ver checklist</a>
</div>
@endif
