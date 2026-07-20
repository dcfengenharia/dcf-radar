<?php

use App\Enums\StatusAssinatura;
use App\Enums\StatusFatura;
use App\Models\Assinatura;
use App\Models\AssinaturaFatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Services\MercadoPagoGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Autoatendimento de assinatura (Fase 10 do roadmap de maturidade SaaS).
 * assinaturas/assinatura_faturas NÃO têm BelongsToTenant (são tabelas de
 * nível de plataforma, ver comentário em App\Models\Assinatura) — toda
 * consulta aqui filtra tenant_id manualmente, nunca confiar em scope
 * automático.
 */
new class extends Component {
    public Tenant $tenant;

    public string $metodoSelecionado = 'cartao';
    public ?string $planoSelecionadoId = null;
    public ?string $motivoCancelamento = null;

    public function mount(Tenant $tenant): void
    {
        abort_unless(Auth::user()->podeGerenciarTenant($tenant), 403);

        $this->tenant = $tenant;
        $this->planoSelecionadoId = $this->assinaturaAtual?->plano_id;
    }

    #[Computed]
    public function assinaturaAtual(): ?Assinatura
    {
        return $this->tenant->assinaturaAtual();
    }

    #[Computed]
    public function planosAtivos(): Collection
    {
        return Plano::where('ativo', true)->orderBy('preco_mensal')->get();
    }

    #[Computed]
    public function faturas(): Collection
    {
        return AssinaturaFatura::where('tenant_id', $this->tenant->id)
            ->orderByDesc('vencimento')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function faturaPendente(): ?AssinaturaFatura
    {
        return $this->faturas->firstWhere('status', StatusFatura::Pendente);
    }

    #[Computed]
    public function publicKey(): ?string
    {
        return config('services.mercadopago.public_key');
    }

    public function confirmarCartao(string $cardTokenId, string $payerEmail): void
    {
        $plano = Plano::findOrFail($this->planoSelecionadoId);

        try {
            $resposta = app(MercadoPagoGateway::class)->criarAssinaturaCartao(
                "Plano {$plano->nome} — {$this->tenant->name}",
                $payerEmail,
                $cardTokenId,
                (float) $plano->preco_mensal,
                $this->tenant->id,
                route('app.empresa.assinatura')
            );

            if (($resposta['status'] ?? null) !== 'authorized') {
                $this->dispatch('show-toast', message: 'O Mercado Pago não autorizou o cartão. Tente outro cartão.', tipo: 'error');
                return;
            }

            $this->tenant->assinaturas()->create([
                'plano_id' => $plano->id,
                'status' => StatusAssinatura::Ativa->value,
                'origem' => 'mercadopago',
                'metodo_pagamento' => 'cartao',
                'mp_preapproval_id' => $resposta['id'],
                'inicio' => now()->toDateString(),
                'renovar_em' => now()->addMonth()->toDateString(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('show-toast', message: 'Não foi possível confirmar o pagamento agora. Tente novamente em instantes.', tipo: 'error');
            return;
        }

        unset($this->assinaturaAtual, $this->faturas);
        $this->dispatch('show-toast', message: 'Assinatura confirmada! Cobrança automática todo mês.');
    }

    public function gerarCobranca(): void
    {
        $plano = Plano::findOrFail($this->planoSelecionadoId);
        $metodo = $this->metodoSelecionado;
        $vencimento = $metodo === 'pix' ? now()->addDays(2) : now()->addDays(3);

        try {
            $novaAssinatura = $this->tenant->assinaturas()->create([
                'plano_id' => $plano->id,
                'status' => $this->assinaturaAtual?->status->value ?? StatusAssinatura::Trial->value,
                'origem' => 'mercadopago',
                'metodo_pagamento' => $metodo,
                'inicio' => now()->toDateString(),
                'renovar_em' => $vencimento->toDateString(),
            ]);

            $gateway = app(MercadoPagoGateway::class);
            $resposta = $metodo === 'pix'
                ? $gateway->criarCobrancaPix("Plano {$plano->nome} — {$this->tenant->name}", Auth::user()->email, (float) $plano->preco_mensal, $this->tenant->id, $vencimento)
                : $gateway->criarCobrancaBoleto("Plano {$plano->nome} — {$this->tenant->name}", Auth::user()->email, (float) $plano->preco_mensal, $this->tenant->id, $vencimento);

            AssinaturaFatura::create([
                'tenant_id' => $this->tenant->id,
                'assinatura_id' => $novaAssinatura->id,
                'metodo_pagamento' => $metodo,
                'valor' => $plano->preco_mensal,
                'status' => StatusFatura::Pendente->value,
                'vencimento' => $vencimento->toDateString(),
                'mp_payment_id' => (string) ($resposta['id'] ?? ''),
                'link_pagamento' => $resposta['point_of_interaction']['transaction_data']['ticket_url'] ?? ($resposta['transaction_details']['external_resource_url'] ?? null),
                'qr_code' => $resposta['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null,
            ]);
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('show-toast', message: 'Não foi possível gerar a cobrança agora. Tente novamente em instantes.', tipo: 'error');
            return;
        }

        unset($this->assinaturaAtual, $this->faturas, $this->faturaPendente);
        $this->dispatch('show-toast', message: $metodo === 'pix' ? 'Pix gerado — escaneie o QR code abaixo.' : 'Boleto gerado — copie o link abaixo.');
    }

    public function cancelarAssinatura(): void
    {
        $atual = $this->assinaturaAtual;
        if (! $atual) {
            return;
        }

        try {
            if ($atual->mp_preapproval_id) {
                app(MercadoPagoGateway::class)->cancelarAssinatura($atual->mp_preapproval_id);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $this->tenant->assinaturas()->create([
            'plano_id' => $atual->plano_id,
            'status' => StatusAssinatura::Cancelada->value,
            'origem' => $atual->origem,
            'metodo_pagamento' => $atual->metodo_pagamento,
            'inicio' => $atual->inicio,
            'cancelada_em' => now(),
            'motivo_cancelamento' => $this->motivoCancelamento ?: 'Cancelado pelo tenant via autoatendimento.',
        ]);

        unset($this->assinaturaAtual);
        $this->motivoCancelamento = null;
        $this->dispatch('show-toast', message: 'Assinatura cancelada.');
    }
};
?>

<div>
    <div class="row g-4">
        <div class="col-md-7">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Plano Atual</h5>
                </div>
                <div class="card-body">
                    @if ($this->assinaturaAtual)
                        <p class="mb-1">
                            <strong>{{ $this->assinaturaAtual->plano->nome }}</strong>
                            <span class="badge bg-label-{{ $this->assinaturaAtual->status->corBadge() }} ms-2">{{ $this->assinaturaAtual->status->label() }}</span>
                        </p>
                        @if ($this->assinaturaAtual->status->value === 'trial' && $this->assinaturaAtual->fim_trial)
                            <p class="text-muted small mb-1">Trial até {{ $this->assinaturaAtual->fim_trial->format('d/m/Y') }}.</p>
                        @endif
                        @if ($this->assinaturaAtual->renovar_em)
                            <p class="text-muted small mb-1">Próxima cobrança: {{ Carbon::parse($this->assinaturaAtual->renovar_em)->format('d/m/Y') }}.</p>
                        @endif
                        @if ($this->assinaturaAtual->status->concedeAcesso() && $this->assinaturaAtual->origem !== 'manual')
                            <button class="btn btn-sm btn-outline-danger mt-2" data-bs-toggle="modal" data-bs-target="#modalCancelarAssinatura">
                                Cancelar assinatura
                            </button>
                        @endif
                    @else
                        <p class="text-muted mb-0">Nenhuma assinatura ainda — escolha um plano abaixo.</p>
                    @endif
                </div>
            </div>

            @if ($this->faturaPendente)
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Cobrança pendente</h5>
                    </div>
                    <div class="card-body" wire:poll.10s>
                        <p class="mb-2">Valor: R$ {{ number_format($this->faturaPendente->valor, 2, ',', '.') }} — vence em {{ $this->faturaPendente->vencimento->format('d/m/Y') }}.</p>
                        @if ($this->faturaPendente->metodo_pagamento === 'pix' && $this->faturaPendente->qr_code)
                            <img src="data:image/png;base64,{{ $this->faturaPendente->qr_code }}" alt="QR Code Pix" style="max-width: 220px;">
                        @endif
                        @if ($this->faturaPendente->link_pagamento)
                            <p class="mt-2"><a href="{{ $this->faturaPendente->link_pagamento }}" target="_blank" rel="noopener">Abrir cobrança no Mercado Pago</a></p>
                        @endif
                        <p class="text-muted small mb-0">Esta página atualiza sozinha assim que o pagamento for confirmado.</p>
                    </div>
                </div>
            @else
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Assinar / Trocar de Plano</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-medium text-muted mb-1">Plano</label>
                            <select class="form-select" wire:model="planoSelecionadoId">
                                <option value="">— Selecione —</option>
                                @foreach ($this->planosAtivos as $plano)
                                    <option value="{{ $plano->id }}">{{ $plano->nome }} (R$ {{ number_format($plano->preco_mensal, 2, ',', '.') }}/mês)</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium text-muted mb-1">Forma de pagamento</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" id="metodoCartao" wire:model.live="metodoSelecionado" value="cartao">
                                <label class="btn btn-outline-primary" for="metodoCartao">Cartão</label>
                                <input type="radio" class="btn-check" id="metodoPix" wire:model.live="metodoSelecionado" value="pix">
                                <label class="btn btn-outline-primary" for="metodoPix">Pix</label>
                                <input type="radio" class="btn-check" id="metodoBoleto" wire:model.live="metodoSelecionado" value="boleto">
                                <label class="btn btn-outline-primary" for="metodoBoleto">Boleto</label>
                            </div>
                        </div>

                        @if ($metodoSelecionado === 'cartao')
                            <button
                                type="button"
                                class="btn btn-outline-primary mb-3"
                                @disabled(! $planoSelecionadoId)
                                onclick="iniciarCardBrick('{{ $this->publicKey }}', '{{ $planoSelecionadoId }}')"
                            >
                                Carregar formulário de cartão
                            </button>
                            <div wire:ignore id="mp-card-brick-container"></div>
                            <p class="text-muted small">Cobrança recorrente automática todo mês.</p>
                        @else
                            <button class="btn btn-primary" wire:click="gerarCobranca" wire:loading.attr="disabled" @disabled(! $planoSelecionadoId)>
                                <span wire:loading.remove wire:target="gerarCobranca">Gerar cobrança de {{ $metodoSelecionado === 'pix' ? 'Pix' : 'Boleto' }}</span>
                                <span wire:loading wire:target="gerarCobranca"><i class="bx bx-loader-alt bx-spin me-2"></i>Gerando...</span>
                            </button>
                            <p class="text-muted small mt-2">Sem cobrança automática — você paga de novo a cada ciclo, avisamos antes do vencimento.</p>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-md-5">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Histórico de Faturas</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Vencimento</th>
                                <th>Valor</th>
                                <th>Método</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->faturas as $fatura)
                                <tr>
                                    <td>{{ $fatura->vencimento->format('d/m/Y') }}</td>
                                    <td>R$ {{ number_format($fatura->valor, 2, ',', '.') }}</td>
                                    <td class="text-capitalize">{{ $fatura->metodo_pagamento }}</td>
                                    <td><span class="badge bg-label-{{ $fatura->status->corBadge() }}">{{ $fatura->status->label() }}</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">Nenhuma fatura ainda.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal: Cancelar Assinatura --}}
    <div class="modal fade" id="modalCancelarAssinatura" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cancelar assinatura</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja cancelar? Você perde acesso ao final do período já pago.</p>
                    <textarea class="form-control" wire:model="motivoCancelamento" rows="2" placeholder="Motivo (opcional)"></textarea>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                    <button class="btn btn-danger" wire:click="cancelarAssinatura" data-bs-dismiss="modal">Confirmar cancelamento</button>
                </div>
            </div>
        </div>
    </div>
</div>

@script
<script>
    // Card Payment Brick (Mercado Pago) - tokeniza o cartao no navegador,
    // o dado do cartao nunca toca o servidor. So testavel de ponta a
    // ponta quando houver credenciais reais (ver CLAUDE.md).
    $wire.on('show-toast', ({ message, tipo }) => {
        if (typeof toastr === 'undefined') return;
        toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
        tipo === 'error' ? toastr.error(message) : toastr.success(message);
    });

    let brickController = null;

    window.iniciarCardBrick = async function (publicKey, planoId) {
        if (! publicKey || ! planoId || ! window.MercadoPago) return;
        if (brickController) {
            brickController.unmount();
            brickController = null;
        }

        const mp = new window.MercadoPago(publicKey, { locale: 'pt-BR' });
        const bricksBuilder = mp.bricks();

        brickController = await bricksBuilder.create('cardPayment', 'mp-card-brick-container', {
            initialization: { amount: 1 },
            callbacks: {
                onSubmit: (formData) => new Promise((resolve, reject) => {
                    $wire.call('confirmarCartao', formData.token, formData.payer.email)
                        .then(resolve)
                        .catch(reject);
                }),
                onError: (error) => console.error('Mercado Pago Brick error', error),
            },
        });
    };
</script>
@endscript
