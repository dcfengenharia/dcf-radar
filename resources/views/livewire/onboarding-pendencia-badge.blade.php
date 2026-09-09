<?php

use App\Support\Onboarding\OnboardingChecklist;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function pendencias(): array
    {
        return OnboardingChecklist::pendenciasAtuais();
    }

    #[On('onboarding-atualizado')]
    public function refresh(): void
    {
        unset($this->pendencias);
    }
};

?>

<div class="mt-auto">
    @if ($this->pendencias !== [])
    <div class="px-3 mb-3">
        <a href="{{ route('app.onboarding') }}" class="btn btn-danger w-100 d-flex align-items-center justify-content-center gap-1">
            <i class="bx bx-error-circle"></i> Configuração Pendente
        </a>
    </div>
    @endif
</div>
