<?php

use App\Models\AvisoPlataforma;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Popup global de "avisos da plataforma" (mensagens importantes do dono
 * do negócio, gerenciadas em /admin/avisos). Incluído sem @persist em
 * contentNavbarLayout.blade.php pra remontar a cada carregamento de
 * página — pega avisos novos criados no meio da sessão e reavalia a
 * flag de sessão corretamente em cada navegação (wire:navigate inclusive).
 *
 * Duas camadas de "já visto": dispensa PERMANENTE em banco
 * (aviso_plataforma_dispensas, só grava se o checkbox foi marcado) e uma
 * flag de sessão (avisos_plataforma_exibidos) que evita repopular o
 * mesmo aviso em toda navegação dentro do mesmo login, mesmo sem marcar
 * o checkbox — como o login regenera a sessão, a flag some sozinha a
 * cada novo login.
 */
new class extends Component {
    public bool $naoMostrarNovamente = false;

    #[Computed]
    public function avisosPendentes(): \Illuminate\Support\Collection
    {
        $exibidos = session('avisos_plataforma_exibidos', []);

        return Auth::user()->avisosPlataformaPendentes()
            ->reject(fn ($aviso) => in_array($aviso->id, $exibidos, true))
            ->values();
    }

    #[Computed]
    public function avisoAtual(): ?AvisoPlataforma
    {
        return $this->avisosPendentes->first();
    }

    public function fecharAviso(): void
    {
        $aviso = $this->avisoAtual;
        if (! $aviso) {
            return;
        }

        if ($this->naoMostrarNovamente) {
            Auth::user()->avisosPlataformaDispensados()->syncWithoutDetaching([$aviso->id]);
        }

        $exibidos = session('avisos_plataforma_exibidos', []);
        $exibidos[] = $aviso->id;
        session(['avisos_plataforma_exibidos' => $exibidos]);

        $this->naoMostrarNovamente = false;
        unset($this->avisosPendentes, $this->avisoAtual);

        $this->dispatch('aviso-plataforma-fechado', temProximo: $this->avisoAtual !== null);
    }
};
?>

<div wire:ignore.self class="modal fade" id="avisoPlataformaModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            @if ($this->avisoAtual)
            @php
                $cor = match ($this->avisoAtual->tipo) {
                    \App\Enums\TipoAvisoPlataforma::Urgente => 'danger',
                    \App\Enums\TipoAvisoPlataforma::Aviso => 'warning',
                    default => 'primary',
                };
            @endphp
            <div wire:key="aviso-{{ $this->avisoAtual->id }}">
                @if ($this->avisoAtual->imagem_promocional)
                {{-- Aviso promocional: imagem em tela cheia + botão de link --}}
                <img src="{{ Storage::disk('public')->url($this->avisoAtual->imagem_promocional) }}"
                     class="w-100" style="display: block; border-radius: calc(.5rem - 1px) calc(.5rem - 1px) 0 0;">
                <div class="modal-body">
                    <h6 class="mb-2">{{ $this->avisoAtual->titulo }}</h6>
                    @if ($this->avisoAtual->mensagem)
                    <div class="mb-3 aviso-plataforma-conteudo">{!! $this->avisoAtual->mensagem !!}</div>
                    @endif
                    @if ($this->avisoAtual->link_url)
                    <a href="{{ $this->avisoAtual->link_url }}" target="_blank" rel="noopener"
                       wire:click="fecharAviso" class="btn btn-{{ $cor }} w-100 mb-3">
                        {{ $this->avisoAtual->link_texto ?: 'Saiba mais' }}
                    </a>
                    @endif
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="avisoNaoMostrarNovamente" wire:model="naoMostrarNovamente">
                        <label class="form-check-label small" for="avisoNaoMostrarNovamente">Não mostrar este aviso novamente</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-{{ $cor }}" wire:click="fecharAviso">Entendi</button>
                </div>
                @else
                <div class="modal-header border-bottom border-{{ $cor }} bg-label-{{ $cor }}">
                    <h5 class="modal-title"><i class="bx bx-bell me-2"></i>{{ $this->avisoAtual->titulo }}</h5>
                </div>
                <div class="modal-body">
                    {{-- mensagem já sanitizada via HTMLPurifier no model (App\Models\AvisoPlataforma::setMensagemAttribute) --}}
                    <div class="mb-3 aviso-plataforma-conteudo">{!! $this->avisoAtual->mensagem !!}</div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="avisoNaoMostrarNovamente" wire:model="naoMostrarNovamente">
                        <label class="form-check-label small" for="avisoNaoMostrarNovamente">Não mostrar este aviso novamente</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-{{ $cor }}" wire:click="fecharAviso">Entendi</button>
                </div>
                @endif
            </div>
            @endif
        </div>
    </div>
</div>

<style>
    .aviso-plataforma-conteudo img { max-width: 100%; height: auto; }
    .aviso-plataforma-conteudo p:last-child { margin-bottom: 0; }
</style>

@script
<script>
    const elAvisoPlataforma = document.getElementById('avisoPlataformaModal');

    if (@json($this->avisoAtual !== null)) {
        new bootstrap.Modal(elAvisoPlataforma).show();
    }

    $wire.on('aviso-plataforma-fechado', ({ temProximo }) => {
        if (!temProximo) {
            bootstrap.Modal.getInstance(elAvisoPlataforma)?.hide();
        }
    });
</script>
@endscript
