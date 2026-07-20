<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\Work;
use App\Models\Client;
use App\Models\Perfil;
use Illuminate\Support\Facades\Auth;

new class extends Component {

  public ?string $workId = null;
  public string  $name   = '';
  public ?string $clientId            = null;
  public ?string $location            = null;
  public ?string $budgetTotal         = null;
  public ?string $startDateBaseline   = null;
  public ?string $endDateBaseline     = null;
  public string  $status              = 'planejamento';
  public bool    $isEditing           = false;
  public string  $formTitle           = 'Cadastrar Obra';

  public function mount(): void
  {
    $this->clearForm();
  }

  public function getClientsProperty()
  {
    return Client::orderBy('name')->get(['id', 'name']);
  }

  protected function rules(): array
  {
    return [
      'name'              => 'required|min:3|max:255',
      'clientId'          => 'required|exists:clients,id',
      'location'          => 'nullable|max:255',
      'budgetTotal'       => 'nullable|numeric|min:0',
      'startDateBaseline' => 'nullable|date',
      'endDateBaseline'   => 'nullable|date|after_or_equal:startDateBaseline',
      'status'            => 'required|in:planejamento,em_andamento,paralisada,concluida',
    ];
  }

  protected function messages(): array
  {
    return [
      'name.required'              => 'O nome da obra é obrigatório.',
      'name.min'                   => 'O nome deve ter pelo menos 3 caracteres.',
      'clientId.required'          => 'Selecione um cliente para esta obra.',
      'clientId.exists'            => 'O cliente selecionado não é válido.',
      'budgetTotal.numeric'        => 'O orçamento deve ser um valor numérico.',
      'endDateBaseline.after_or_equal' => 'A data de fim deve ser igual ou posterior à data de início.',
    ];
  }

  #[On('edit-work')]
  public function editWork(string $id): void
  {
    $work = Work::with('client')->findOrFail($id);

    $this->workId            = $work->id;
    $this->name              = $work->name;
    $this->clientId          = $work->client_id;
    $this->location          = $work->location;
    $this->budgetTotal       = $work->budget_total ? number_format($work->budget_total, 2, '.', '') : null;
    $this->startDateBaseline = $work->start_date_baseline?->format('Y-m-d');
    $this->endDateBaseline   = $work->end_date_baseline?->format('Y-m-d');
    $this->status            = $work->status;
    $this->isEditing         = true;
    $this->formTitle         = 'Editar Obra';

    $this->dispatch('open-obra-modal');
  }

  public function saveWork(): void
  {
    $this->validate();

    if ($this->isEditing) {
      $this->updateWork();
    } else {
      $this->createWork();
    }
  }

  private function createWork(): void
  {
    $this->authorize('create', Work::class);

    $limite = Auth::user()->tenant->limiteObras();
    if ($limite !== null && Work::count() >= $limite) {
      $this->addError('name', "Seu plano permite no máximo {$limite} obra(s). Fale com o administrador da conta pra aumentar o limite.");

      return;
    }

    $work = Work::create([
      'client_id'            => $this->clientId,
      'name'                 => $this->name,
      'location'             => $this->location,
      'budget_total'         => $this->budgetTotal ? (float) $this->budgetTotal : null,
      'start_date_baseline'  => $this->startDateBaseline ?: null,
      'end_date_baseline'    => $this->endDateBaseline ?: null,
      'status'               => $this->status,
    ]);

    // Vincula quem criou a obra como Gerente de Planejamento — exceto se
    // for o próprio criador do tenant, que já foi vinculado como Admin
    // (imutável) pelo hook Work::garantirCriadorDoTenantComoAdmin().
    if (Auth::id() !== $work->tenant->criado_por_id) {
      $perfilGerente = Perfil::porSlugPadrao($work->tenant, 'gerente_planejamento');
      $work->users()->syncWithoutDetaching([Auth::id() => ['perfil_id' => $perfilGerente?->id]]);
    }

    $this->clearForm();
    $this->dispatch('close-obra-modal');
    $this->dispatch('work-created');
    $this->dispatch('show-toast', message: 'Obra cadastrada com sucesso!');
  }

  private function updateWork(): void
  {
    $work = Work::findOrFail($this->workId);
    $this->authorize('update', $work);

    $work->update([
      'client_id'            => $this->clientId,
      'name'                 => $this->name,
      'location'             => $this->location,
      'budget_total'         => $this->budgetTotal ? (float) $this->budgetTotal : null,
      'start_date_baseline'  => $this->startDateBaseline ?: null,
      'end_date_baseline'    => $this->endDateBaseline ?: null,
      'status'               => $this->status,
    ]);

    $this->clearForm();
    $this->dispatch('close-obra-modal');
    $this->dispatch('work-updated');
    $this->dispatch('show-toast', message: 'Obra atualizada com sucesso!');
  }

  public function clearForm(): void
  {
    $this->reset([
      'workId', 'name', 'clientId', 'location',
      'budgetTotal', 'startDateBaseline', 'endDateBaseline', 'isEditing',
    ]);
    $this->status    = 'planejamento';
    $this->formTitle = 'Cadastrar Obra';
  }
};
?>

<div wire:ignore.self class="modal fade" id="obraModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">

            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-semibold">
                    <i class="bx {{ $isEditing ? 'bx-edit' : 'bx-hard-hat' }} me-2"></i>
                    {{ $isEditing ? 'Editar Obra' : 'Cadastrar Nova Obra' }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" wire:click="clearForm"></button>
            </div>

            <form wire:submit.prevent="saveWork">
                <div class="modal-body pt-4">

                    @if ($errors->any())
                        <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
                            <i class="bx bx-error-circle me-2"></i> Corrija os campos destacados abaixo antes de continuar.
                        </div>
                    @endif

                    {{-- Linha 1: Nome + Cliente --}}
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="work-name">
                                Nome da Obra <span class="text-danger">*</span>
                            </label>
                            <input
                                type="text"
                                id="work-name"
                                wire:model="name"
                                class="form-control @error('name') is-invalid @enderror"
                                placeholder="Ex: Residencial das Palmeiras — Bloco A"
                            >
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="work-status">Status</label>
                            <select id="work-status" wire:model="status" class="form-select @error('status') is-invalid @enderror">
                                <option value="planejamento">Planejamento</option>
                                <option value="em_andamento">Em Andamento</option>
                                <option value="paralisada">Paralisada</option>
                                <option value="concluida">Concluída</option>
                            </select>
                            @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    {{-- Linha 2: Cliente + Localização --}}
                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="work-client">
                                Cliente <span class="text-danger">*</span>
                            </label>
                            <select id="work-client" wire:model="clientId" class="form-select @error('clientId') is-invalid @enderror" @if ($this->clients->isEmpty()) disabled @endif>
                                <option value="">— Selecione um cliente —</option>
                                @foreach ($this->clients as $client)
                                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                                @endforeach
                            </select>
                            @error('clientId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            @if ($this->clients->isEmpty())
                                <div class="form-text text-warning">
                                    <i class="bx bx-error-circle"></i>
                                    Cadastre um cliente antes de criar uma obra.
                                    <a href="{{ route('cadastros.clientes.index') }}">Cadastrar Cliente</a>
                                </div>
                            @endif
                        </div>

                        <div class="col-md-7 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="work-location">Localização</label>
                            <input
                                type="text"
                                id="work-location"
                                wire:model="location"
                                class="form-control @error('location') is-invalid @enderror"
                                placeholder="Ex: Av. Paulista, 1000 — São Paulo / SP"
                            >
                            @error('location') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    {{-- Linha 3: Orçamento + Datas --}}
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="work-budget">Orçamento Total (R$)</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input
                                    type="number"
                                    id="work-budget"
                                    wire:model="budgetTotal"
                                    class="form-control @error('budgetTotal') is-invalid @enderror"
                                    placeholder="0,00"
                                    min="0"
                                    step="0.01"
                                >
                                @error('budgetTotal') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="work-start">Data de Início (Linha de Base)</label>
                            <input
                                type="date"
                                id="work-start"
                                wire:model="startDateBaseline"
                                class="form-control @error('startDateBaseline') is-invalid @enderror"
                            >
                            @error('startDateBaseline') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-medium text-muted mb-1" for="work-end">Data de Fim (Linha de Base)</label>
                            <input
                                type="date"
                                id="work-end"
                                wire:model="endDateBaseline"
                                class="form-control @error('endDateBaseline') is-invalid @enderror"
                            >
                            @error('endDateBaseline') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <small class="text-muted"><span class="text-danger">*</span> Campos de preenchimento obrigatório!</small>
                        </div>
                    </div>

                </div>

                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-label-secondary shadow-none" data-bs-dismiss="modal" wire:click="clearForm">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary shadow-none" @if ($this->clients->isEmpty()) disabled @endif>
                        <span wire:loading.remove wire:target="saveWork">
                            {{ $isEditing ? 'Salvar Alterações' : 'Cadastrar Obra' }}
                        </span>
                        <span wire:loading wire:target="saveWork">
                            <i class="bx bx-loader-alt bx-spin me-2"></i>Salvando...
                        </span>
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

@script
<script>
    $wire.on('open-obra-modal', () => {
        const el = document.getElementById('obraModal');
        if (el) bootstrap.Modal.getOrCreateInstance(el).show();
    });

    $wire.on('close-obra-modal', () => {
        const el = document.getElementById('obraModal');
        if (el) {
            bootstrap.Modal.getInstance(el)?.hide();
            setTimeout(() => {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
                document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            }, 150);
        }
    });

    {{-- 'show-toast' NÃO é escutado aqui — ⚡index.blade.php (única página que
         inclui este componente) já tem o listener; duplicar aqui fazia o
         toastr aparecer 2x pra cada mensagem, já que dispatch() sem ->to()
         propaga pro bus global do Livewire e ambos os $wire.on da página
         pegavam o mesmo evento. --}}
</script>
@endscript
