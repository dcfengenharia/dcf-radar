{{--
    Ciclo 23, Etapa 23.5.B (Seção 20) — modal compartilhado "Avaliar
    resultado", incluído sem alteração nos 3 pontos de UI (Lookahead,
    Estoque, Biblioteca Corporativa). Depende só das propriedades/métodos
    de `App\Support\Concerns\LidaComReaplicacaoLicao` — nunca referencia
    nada específico de um componente em particular.
--}}
@if ($avaliarReaplicacaoId)
    <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Avaliar resultado da reaplicação</h5>
                    <button type="button" class="btn-close" wire:click="fecharAvaliarReaplicacao"></button>
                </div>
                <div class="modal-body">
                    @if ($reaplicacaoErro)
                        <div class="alert alert-danger py-2 px-3 small">{{ $reaplicacaoErro }}</div>
                    @endif
                    <p class="text-muted small">
                        Esta avaliação registra o que a equipe observou — nunca substitui análise técnica formal, nem prova que a lição evitou um problema específico.
                    </p>
                    <label class="form-label">Resultado</label>
                    <select class="form-select mb-3" wire:model="avaliarResultado">
                        <option value="">Selecione...</option>
                        @foreach (\App\Enums\ResultadoAvaliacaoReaplicacao::cases() as $resultadoOpcao)
                            <option value="{{ $resultadoOpcao->value }}">{{ $resultadoOpcao->label() }}</option>
                        @endforeach
                    </select>
                    <label class="form-label">Observação (opcional)</label>
                    <textarea class="form-control" rows="3" wire:model="avaliarObservacao"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" wire:click="fecharAvaliarReaplicacao">Cancelar</button>
                    <button type="button" class="btn btn-primary" wire:click="confirmarAvaliar">Registrar avaliação</button>
                </div>
            </div>
        </div>
    </div>
@endif
