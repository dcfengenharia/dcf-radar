<?php

use App\Support\TemplateConvite;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $assunto  = '';
    public string $mensagem = '';

    public function mount(): void
    {
        $tenant = Auth::user()->tenant;
        $this->assunto  = $tenant->convite_email_assunto ?: TemplateConvite::assuntoPadrao();
        $this->mensagem = $tenant->convite_email_mensagem ?: TemplateConvite::mensagemPadrao();
    }

    #[Computed]
    public function preview(): array
    {
        return [
            'assunto' => TemplateConvite::substituir($this->assunto, 'Edifício Aurora', 'Maria Silva', 'Engenheiro'),
            'mensagem' => TemplateConvite::substituir($this->mensagem, 'Edifício Aurora', 'Maria Silva', 'Engenheiro'),
        ];
    }

    public function salvar(): void
    {
        abort_unless(Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.convite_config', 'editar'), 403);

        $this->validate([
            'assunto' => 'required|string|max:255',
            'mensagem' => 'required|string|max:2000',
        ], [], [
            'assunto' => 'assunto',
            'mensagem' => 'mensagem',
        ]);

        $tenant = Auth::user()->tenant;
        $tenant->update([
            'convite_email_assunto' => $this->assunto,
            'convite_email_mensagem' => $this->mensagem,
        ]);

        $this->dispatch('show-toast', message: 'Modelo de e-mail de convite atualizado.');
    }

    public function restaurarPadrao(): void
    {
        $this->assunto  = TemplateConvite::assuntoPadrao();
        $this->mensagem = TemplateConvite::mensagemPadrao();
    }
};
?>

<div>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3"><i class="bx bx-envelope me-2"></i>Modelo do e-mail de convite</h5>
                    <p class="text-muted small">
                        Esse texto é usado sempre que alguém for convidado por e-mail pra
                        participar de uma obra. Você pode usar as variáveis
                        <code>@{{obra}}</code>, <code>@{{convidado_por}}</code> e
                        <code>@{{papel}}</code> — elas são substituídas automaticamente.
                    </p>

                    <div class="mb-3">
                        <label class="form-label">Assunto</label>
                        <input type="text" class="form-control @error('assunto') is-invalid @enderror"
                               wire:model="assunto">
                        @error('assunto')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Mensagem</label>
                        <textarea class="form-control @error('mensagem') is-invalid @enderror" rows="6"
                                  wire:model="mensagem"></textarea>
                        @error('mensagem')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    @if (Auth::user()->temPermissaoEmAlgumaObraDoTenant('cadastros.convite_config', 'editar'))
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" wire:click="salvar" wire:loading.attr="disabled">
                            <i class="bx bx-save me-1"></i>Salvar
                        </button>
                        <button class="btn btn-outline-secondary" wire:click="restaurarPadrao">
                            Restaurar texto padrão
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bx bx-show me-2"></i>Prévia</h6>
                    <p class="text-muted small mb-3">
                        Com dados de exemplo (obra "Edifício Aurora", convidado por Maria Silva,
                        papel Engenheiro):
                    </p>
                    <div class="border rounded p-3 bg-light">
                        <div class="fw-bold mb-2">{{ $this->preview['assunto'] }}</div>
                        <p class="mb-3" style="white-space: pre-line">{{ $this->preview['mensagem'] }}</p>
                        <button class="btn btn-sm btn-primary" disabled>Aceitar Convite</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
