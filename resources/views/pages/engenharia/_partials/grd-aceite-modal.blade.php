{{--
    Ciclo 18, Etapa 18.5.9 — modal de registro de aceite/assinatura de
    recebimento. Canvas com Pointer Events (unifica mouse e touch — crítico
    pra coleta em tablet/celular no campo, sem UI desktop-only). A
    assinatura NUNCA é validada aqui — só convertida pra PNG base64 e
    enviada pro backend (App\Actions\Engenharia\RegistrarAceiteEntrega faz
    toda a validação real de formato/tamanho).
--}}
@php($gd = $this->grdDestinatarioEmAceite)
@if ($gd)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content" x-data="{
                temTraco: false,
                desenhando: false,
                ultimo: null,
                ctx: null,
                iniciarCanvas() {
                    const canvas = this.$refs.canvasAssinatura;
                    if (!canvas) return;
                    const rect = canvas.getBoundingClientRect();
                    canvas.width = rect.width;
                    canvas.height = 160;
                    this.ctx = canvas.getContext('2d');
                    this.ctx.lineWidth = 2;
                    this.ctx.lineCap = 'round';
                    this.ctx.strokeStyle = '#000';
                    this.temTraco = false;
                },
                posicao(e) {
                    const rect = this.$refs.canvasAssinatura.getBoundingClientRect();
                    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
                },
                iniciar(e) {
                    this.desenhando = true;
                    this.temTraco = true;
                    this.ultimo = this.posicao(e);
                },
                tracar(e) {
                    if (!this.desenhando) return;
                    const p = this.posicao(e);
                    this.ctx.beginPath();
                    this.ctx.moveTo(this.ultimo.x, this.ultimo.y);
                    this.ctx.lineTo(p.x, p.y);
                    this.ctx.stroke();
                    this.ultimo = p;
                },
                parar() { this.desenhando = false; },
                limpar() {
                    if (this.ctx) this.ctx.clearRect(0, 0, this.$refs.canvasAssinatura.width, this.$refs.canvasAssinatura.height);
                    this.temTraco = false;
                },
                confirmar() {
                    const dataUrl = ($wire.aceiteTipo === 'assinatura' && this.temTraco)
                        ? this.$refs.canvasAssinatura.toDataURL('image/png')
                        : null;
                    $wire.confirmarAceite(dataUrl);
                }
             }"
             x-init="$nextTick(() => { if ($wire.aceiteTipo === 'assinatura') iniciarCanvas(); })">
            <div class="modal-header">
                <h5 class="modal-title">Registrar recebimento — {{ $gd->nome_snapshot }}</h5>
                <button type="button" class="btn-close" wire:click="fecharModalAceite"></button>
            </div>
            <div class="modal-body">
                @if ($aceiteErro)
                    <div class="alert alert-danger">{{ $aceiteErro }}</div>
                @endif

                <div class="mb-3">
                    <label class="form-label">Nome de quem recebeu</label>
                    <input type="text" class="form-control" wire:model="aceiteNomeRecebedor">
                </div>

                <div class="mb-3">
                    <label class="form-label">Tipo de aceite</label>
                    <select class="form-select" wire:model="aceiteTipo" x-on:change="if ($event.target.value === 'assinatura') $nextTick(() => iniciarCanvas())">
                        <option value="assinatura">Assinatura</option>
                        <option value="sem_assinatura">Aceite sem assinatura</option>
                    </select>
                </div>

                <div class="mb-3" x-show="$wire.aceiteTipo === 'assinatura'" x-cloak>
                    <label class="form-label d-flex justify-content-between align-items-center">
                        <span>Assinatura</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="limpar()">Limpar</button>
                    </label>
                    <canvas x-ref="canvasAssinatura"
                            style="width: 100%; height: 160px; touch-action: none; border: 1px dashed #ccc; border-radius: 4px; background: #fff;"
                            x-on:pointerdown="iniciar($event)"
                            x-on:pointermove="tracar($event)"
                            x-on:pointerup="parar()"
                            x-on:pointerleave="parar()"
                    ></canvas>
                    <small class="text-muted">Assine com o dedo ou o mouse na área acima.</small>
                </div>

                <div class="mb-0">
                    <label class="form-label">Observação (opcional)</label>
                    <textarea class="form-control" rows="2" wire:model="aceiteObservacao"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" wire:click="fecharModalAceite">Cancelar</button>
                <button class="btn btn-primary" x-on:click="confirmar()">Confirmar</button>
            </div>
        </div>
    </div>
</div>
@endif
