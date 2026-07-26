<?php

use App\Enums\TipoFeedback;
use App\Models\Feedback;
use App\Notifications\AgradecimentoFeedbackNotification;
use App\Notifications\NovoFeedbackNotification;
use App\Support\Concerns\ExecutaComTransacaoSegura;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Popup global de "Suporte" — canal de feedback (erro/melhoria/crítica)
 * durante a fase de testes. Dois pontos de entrada (link "Suporte" no
 * footer e alerta "Sistema em testes" na navbar) abrem o MESMO popup
 * via evento global Livewire.dispatch('abrir-suporte', {...}), captado
 * abaixo — nenhum dos dois pontos precisa estar dentro da árvore deste
 * componente. Mesmo esqueleto de avisos-plataforma/⚡popup.blade.php
 * (wire:ignore.self + Bootstrap Modal via @script), mas com abertura
 * sob demanda em vez de auto-abrir por computed property no mount.
 */
new class extends Component {
    use ExecutaComTransacaoSegura;

    public string $tipo = '';
    public string $mensagem = '';
    public string $urlOrigem = '';
    public bool $enviando = false;

    protected function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(array_column(TipoFeedback::cases(), 'value'))],
            'mensagem' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'tipo.required' => 'Selecione o tipo de mensagem.',
            'mensagem.required' => 'Escreva sua mensagem.',
            'mensagem.min' => 'Sua mensagem precisa ter pelo menos :min caracteres.',
            'mensagem.max' => 'Sua mensagem não pode ter mais de :max caracteres.',
        ];
    }

    public function abrir(string $url): void
    {
        $this->urlOrigem = $url;
    }

    public function enviar(): void
    {
        $this->enviando = true;
        $this->mensagem = trim($this->mensagem);

        $chaveThrottle = 'enviar-feedback:'.Auth::id();
        if (RateLimiter::tooManyAttempts($chaveThrottle, 5)) {
            $segundos = RateLimiter::availableIn($chaveThrottle);
            $this->enviando = false;
            $this->addError('mensagem', "Muitos envios em pouco tempo. Tente de novo em {$segundos} segundos.");
            return;
        }

        $this->validate();
        RateLimiter::hit($chaveThrottle, 300);

        $this->transacaoSegura(function () {
            $feedback = Feedback::create([
                'tenant_id' => TenantContext::currentId(),
                'user_id' => Auth::id(),
                'tipo' => $this->tipo,
                'mensagem' => $this->mensagem,
                'url_origem' => $this->urlOrigem ?: null,
            ]);

            Notification::route('mail', 'contato@dcf.eng.br')
                ->notify(new NovoFeedbackNotification($feedback));

            Auth::user()->notify(new AgradecimentoFeedbackNotification());
        }, 'Não foi possível enviar seu feedback neste momento. Verifique sua conexão e tente novamente.');

        $this->enviando = false;

        if ($this->transacaoSeguraFalhou()) {
            return;
        }

        $this->reset(['tipo', 'mensagem', 'urlOrigem']);
        $this->dispatch('fechar-modal-suporte');
        $this->dispatch('show-toast', message: 'Obrigado pelo seu feedback! Sua contribuição foi enviada com sucesso e nos ajudará a melhorar o sistema.');
    }
};
?>

<div wire:ignore.self class="modal fade" id="suportePopupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-support me-2"></i>Suporte</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Encontrou um erro, tem uma sugestão ou uma crítica? Conte pra gente — sua contribuição ajuda a melhorar o sistema durante esta fase de testes.</p>

                <div class="mb-3">
                    <label class="form-label" for="suporteTipo">Tipo de mensagem</label>
                    <select id="suporteTipo" class="form-select @error('tipo') is-invalid @enderror" wire:model="tipo">
                        <option value="">Selecione...</option>
                        @foreach (\App\Enums\TipoFeedback::cases() as $tipoFeedback)
                        <option value="{{ $tipoFeedback->value }}">{{ $tipoFeedback->label() }}</option>
                        @endforeach
                    </select>
                    @error('tipo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="suporteMensagem">Mensagem</label>
                    <textarea id="suporteMensagem" rows="5" class="form-control @error('mensagem') is-invalid @enderror"
                              wire:model="mensagem" placeholder="Descreva com detalhes..."></textarea>
                    @error('mensagem') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" wire:click="enviar" wire:loading.attr="disabled" wire:target="enviar">
                    <span wire:loading.remove wire:target="enviar"><i class="bx bx-send me-1"></i>Enviar feedback</span>
                    <span wire:loading wire:target="enviar"><span class="spinner-border spinner-border-sm me-1"></span>Enviando...</span>
                </button>
            </div>
        </div>
    </div>
</div>

@script
<script>
    const elSuportePopup = document.getElementById('suportePopupModal');

    // Livewire.on (global) — não $wire.on — porque o footer/navbar disparam
    // Livewire.dispatch() de FORA da árvore deste componente (onclick puro,
    // sem contexto de componente); $wire.on só recebe dispatches destinados
    // a este componente especificamente (ex.: os dois abaixo, disparados
    // pelo próprio backend via $this->dispatch() dentro de enviar()).
    Livewire.on('abrir-suporte', ({ url }) => {
        $wire.abrir(url);
        bootstrap.Modal.getOrCreateInstance(elSuportePopup).show();
    });

    $wire.on('fechar-modal-suporte', () => {
        bootstrap.Modal.getInstance(elSuportePopup)?.hide();
    });

    $wire.on('show-toast', ({ message, type = 'success' }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            (toastr[type] || toastr.success)(message);
        }
    });
</script>
@endscript
