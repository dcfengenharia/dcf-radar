<?php

use App\Enums\TipoAvisoPlataforma;
use App\Models\AvisoPlataforma;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
  use WithFileUploads;

  public bool $modalAberto = false;
  public ?string $editandoId = null;
  public string $titulo = '';
  public string $mensagem = '';
  public string $tipo = 'informativo';
  public bool $ativo = true;

  /** Upload temporário disparado pelo botão de imagem do editor Quill — nunca persiste sozinho, só via processarImagemAviso(). */
  public $imagemTemporaria = null;

  /** Imagem de tela cheia (promoção) — caminho relativo já salvo no disco 'public', ou null. */
  public $imagemPromocionalTemp = null;
  public ?string $imagemPromocional = null;
  public string $linkUrl = '';
  public string $linkTexto = '';

  /** Direcionamento: true = todas as empresas (padrão); false = só as selecionadas em tenantsSelecionados. */
  public bool $todasEmpresas = true;
  public array $tenantsSelecionados = [];

  #[Computed]
  public function avisos(): \Illuminate\Support\Collection
  {
    return AvisoPlataforma::withCount(['dispensas', 'tenants'])
      ->orderByDesc('ativo')
      ->orderByDesc('created_at')
      ->get();
  }

  #[Computed]
  public function empresasDisponiveis(): \Illuminate\Support\Collection
  {
    return Tenant::orderBy('name')->get(['id', 'name']);
  }

  public function abrirCriar(): void
  {
    $this->resetForm();
    $this->modalAberto = true;
    $this->dispatch('abrir-editor-aviso', mensagem: '');
  }

  public function editar(string $id): void
  {
    $aviso = AvisoPlataforma::with('tenants:id')->findOrFail($id);
    $this->editandoId = $id;
    $this->titulo = $aviso->titulo;
    $this->mensagem = $aviso->mensagem ?? '';
    $this->tipo = $aviso->tipo->value;
    $this->ativo = $aviso->ativo;
    $this->imagemPromocional = $aviso->imagem_promocional;
    $this->linkUrl = $aviso->link_url ?? '';
    $this->linkTexto = $aviso->link_texto ?? '';
    $this->todasEmpresas = $aviso->tenants->isEmpty();
    $this->tenantsSelecionados = $aviso->tenants->pluck('id')->all();
    $this->resetValidation();
    $this->modalAberto = true;
    $this->dispatch('abrir-editor-aviso', mensagem: $aviso->mensagem ?? '');
  }

  /**
   * Imagem de tela cheia (diferente da imagem embutida no texto rico):
   * upload direto e simples, mesmo padrão das fotos do Report — só
   * grava o caminho relativo, a URL é resolvida na hora de exibir.
   */
  public function updatedImagemPromocionalTemp(): void
  {
    $this->validate([
      'imagemPromocionalTemp' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
    ], [], ['imagemPromocionalTemp' => 'imagem promocional']);

    if ($this->imagemPromocional) {
      Storage::disk('public')->delete($this->imagemPromocional);
    }

    $this->imagemPromocional = $this->imagemPromocionalTemp->store('avisos-plataforma', 'public');
    $this->imagemPromocionalTemp = null;
  }

  public function removerImagemPromocional(): void
  {
    if ($this->imagemPromocional) {
      Storage::disk('public')->delete($this->imagemPromocional);
    }
    $this->imagemPromocional = null;
  }

  /**
   * Chamado pelo handler de imagem do Quill: recebe o upload temporário
   * (via $wire.upload), move pro disco público e devolve a URL pra ser
   * inserida no editor como <img>. Mesmo padrão de armazenamento das
   * fotos do Report (disco 'public', storage:link já configurado).
   */
  public function processarImagemAviso(): string
  {
    $this->validate([
      'imagemTemporaria' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
    ], [], ['imagemTemporaria' => 'imagem']);

    $caminho = $this->imagemTemporaria->store('avisos-plataforma', 'public');
    $this->imagemTemporaria = null;

    return Storage::disk('public')->url($caminho);
  }

  public function salvar(): void
  {
    $this->validate([
      'titulo' => 'required|string|max:150',
      'mensagem' => 'nullable|required_without:imagemPromocional|string|max:20000',
      'tipo' => 'required|in:informativo,aviso,urgente',
      'linkUrl' => 'nullable|url|max:500',
      'linkTexto' => 'nullable|string|max:50',
    ], [], [
      'titulo' => 'título',
      'mensagem' => 'mensagem',
      'tipo' => 'tipo',
      'linkUrl' => 'link',
      'linkTexto' => 'texto do botão',
    ]);

    if (! $this->todasEmpresas && empty($this->tenantsSelecionados)) {
      $this->addError('tenantsSelecionados', 'Selecione ao menos uma empresa, ou marque "Todas as empresas".');
      return;
    }

    $dados = [
      'titulo' => $this->titulo,
      'mensagem' => $this->mensagem !== '' ? $this->mensagem : null,
      'tipo' => $this->tipo,
      'ativo' => $this->ativo,
      'imagem_promocional' => $this->imagemPromocional,
      'link_url' => $this->linkUrl !== '' ? $this->linkUrl : null,
      'link_texto' => $this->linkTexto !== '' ? $this->linkTexto : null,
    ];

    if ($this->editandoId) {
      $aviso = AvisoPlataforma::findOrFail($this->editandoId);
      $aviso->update($dados);
    } else {
      $aviso = AvisoPlataforma::create([...$dados, 'criado_por_id' => Auth::id()]);
    }

    $aviso->tenants()->sync($this->todasEmpresas ? [] : $this->tenantsSelecionados);

    $this->resetForm();
    $this->modalAberto = false;
    unset($this->avisos);
    $this->dispatch('show-toast', message: 'Aviso salvo com sucesso.');
  }

  public function descontinuar(string $id): void
  {
    AvisoPlataforma::findOrFail($id)->update(['ativo' => false]);
    unset($this->avisos);
    $this->dispatch('show-toast', message: 'Aviso descontinuado.');
  }

  public function reativar(string $id): void
  {
    AvisoPlataforma::findOrFail($id)->update(['ativo' => true]);
    unset($this->avisos);
    $this->dispatch('show-toast', message: 'Aviso reativado.');
  }

  public function excluir(string $id): void
  {
    AvisoPlataforma::findOrFail($id)->delete();
    unset($this->avisos);
    $this->dispatch('show-toast', message: 'Aviso excluído.');
  }

  private function resetForm(): void
  {
    $this->editandoId = null;
    $this->titulo = '';
    $this->mensagem = '';
    $this->tipo = 'informativo';
    $this->ativo = true;
    $this->imagemPromocional = null;
    $this->imagemPromocionalTemp = null;
    $this->linkUrl = '';
    $this->linkTexto = '';
    $this->todasEmpresas = true;
    $this->tenantsSelecionados = [];
    $this->resetValidation();
  }
};
?>

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="text-muted mb-0">
            Avisos aparecem como popup importante pra qualquer usuário logado (de qualquer empresa)
            assim que fizerem login, até que dispensem marcando "não mostrar novamente".
        </p>
        <button class="btn btn-primary text-nowrap ms-3" wire:click="abrirCriar">
            <i class="bx bx-plus me-1"></i>Novo Aviso
        </button>
    </div>

    <div class="row g-4">
        @forelse ($this->avisos as $aviso)
            @php
                $cor = match ($aviso->tipo) {
                    \App\Enums\TipoAvisoPlataforma::Urgente => 'danger',
                    \App\Enums\TipoAvisoPlataforma::Aviso => 'warning',
                    default => 'primary',
                };
                $tipoLabel = match ($aviso->tipo) {
                    \App\Enums\TipoAvisoPlataforma::Urgente => 'Urgente',
                    \App\Enums\TipoAvisoPlataforma::Aviso => 'Aviso',
                    default => 'Informativo',
                };
            @endphp
            <div class="col-md-4">
                <div class="card h-100 border-{{ $cor }} {{ !$aviso->ativo ? 'opacity-75' : '' }}">
                    @if ($aviso->imagem_promocional)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($aviso->imagem_promocional) }}"
                         class="card-img-top" style="height: 120px; object-fit: cover;">
                    @endif
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2 gap-1 flex-wrap">
                            <span class="badge bg-label-{{ $cor }}">{{ $tipoLabel }}</span>
                            <span class="badge bg-label-info">
                                <i class="bx bx-buildings me-1"></i>{{ $aviso->tenants_count > 0 ? "{$aviso->tenants_count} empresa(s)" : 'Todas as empresas' }}
                            </span>
                            @if (!$aviso->ativo)
                                <span class="badge bg-label-secondary">Descontinuado</span>
                            @endif
                        </div>
                        <h5 class="mb-2">{{ $aviso->titulo }}</h5>
                        @if ($aviso->mensagem)
                        <p class="text-muted small mb-2">{{ \Illuminate\Support\Str::limit(strip_tags($aviso->mensagem), 150) }}</p>
                        @endif
                        @if ($aviso->link_url)
                        <p class="small mb-2"><i class="bx bx-link me-1"></i>{{ $aviso->link_texto ?: 'Link' }}</p>
                        @endif
                        <small class="text-muted">
                            {{ $aviso->dispensas_count }} usuário(s) já dispensaram &middot;
                            criado em {{ $aviso->created_at->format('d/m/Y') }}
                        </small>
                    </div>
                    <div class="card-footer d-flex gap-1">
                        <button class="btn btn-xs btn-outline-secondary py-1 px-2" wire:click="editar('{{ $aviso->id }}')">
                            <i class="bx bx-pencil"></i> Editar
                        </button>
                        @if ($aviso->ativo)
                            <button class="btn btn-xs btn-outline-warning py-1 px-2" wire:click="descontinuar('{{ $aviso->id }}')"
                                    wire:confirm="Descontinuar este aviso? Quem ainda não viu deixa de ver.">
                                <i class="bx bx-block"></i> Descontinuar
                            </button>
                        @else
                            <button class="btn btn-xs btn-outline-success py-1 px-2" wire:click="reativar('{{ $aviso->id }}')">
                                <i class="bx bx-check"></i> Reativar
                            </button>
                        @endif
                        <button class="btn btn-xs btn-outline-danger py-1 px-2" wire:click="excluir('{{ $aviso->id }}')"
                                wire:confirm="Excluir este aviso definitivamente?">
                            <i class="bx bx-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bx bx-bell fs-1 text-muted d-block mb-2"></i>
                        <p class="text-muted mb-3">Nenhum aviso cadastrado ainda.</p>
                        <button class="btn btn-primary" wire:click="abrirCriar">
                            <i class="bx bx-plus me-1"></i>Criar primeiro aviso
                        </button>
                    </div>
                </div>
            </div>
        @endforelse
    </div>

    {{-- Modal criar/editar --}}
    @if ($modalAberto)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ $editandoId ? 'Editar Aviso' : 'Novo Aviso' }}</h5>
                    <button type="button" class="btn-close" wire:click="$set('modalAberto', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('titulo') is-invalid @enderror" wire:model="titulo">
                        @error('titulo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mensagem <span class="text-muted small">(opcional se houver imagem promocional)</span></label>
                        <div wire:ignore>
                            <div id="quillAvisoMensagem" style="min-height: 180px;"></div>
                        </div>
                        <input type="hidden" wire:model="mensagem" id="avisoMensagemHidden">
                        @error('mensagem')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        @error('imagemTemporaria')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipo <span class="text-danger">*</span></label>
                        <select class="form-select @error('tipo') is-invalid @enderror" wire:model="tipo">
                            <option value="informativo">Informativo</option>
                            <option value="aviso">Aviso</option>
                            <option value="urgente">Urgente</option>
                        </select>
                        @error('tipo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" wire:model="ativo" id="avisoAtivo">
                        <label class="form-check-label" for="avisoAtivo">Aviso ativo (aparece pra quem ainda não viu)</label>
                    </div>

                    <hr>
                    <h6 class="mb-2"><i class="bx bx-image-alt me-1"></i>Promoção (opcional)</h6>
                    <p class="text-muted small mb-3">
                        Se enviar uma imagem aqui, o popup mostra ela em tela cheia com um botão
                        de link, em vez do formato de texto acima.
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Imagem em Destaque</label>
                        @if ($imagemPromocional)
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($imagemPromocional) }}"
                                 style="height: 80px; border-radius: .375rem;">
                            <button type="button" class="btn btn-outline-danger btn-sm" wire:click="removerImagemPromocional">
                                <i class="bx bx-trash me-1"></i>Remover
                            </button>
                        </div>
                        @else
                        <input type="file" class="form-control @error('imagemPromocionalTemp') is-invalid @enderror"
                               wire:model="imagemPromocionalTemp" accept="image/*">
                        <div wire:loading wire:target="imagemPromocionalTemp" class="small text-muted mt-1">Enviando...</div>
                        @error('imagemPromocionalTemp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @endif
                    </div>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Link (URL)</label>
                            <input type="text" class="form-control @error('linkUrl') is-invalid @enderror"
                                   wire:model="linkUrl" placeholder="https://...">
                            @error('linkUrl')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Texto do Botão</label>
                            <input type="text" class="form-control @error('linkTexto') is-invalid @enderror"
                                   wire:model="linkTexto" placeholder="Ver Oferta">
                            @error('linkTexto')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <hr>
                    <h6 class="mb-2"><i class="bx bx-buildings me-1"></i>Direcionar Para</h6>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" wire:model.live="todasEmpresas" id="avisoTodasEmpresas">
                        <label class="form-check-label" for="avisoTodasEmpresas">Todas as empresas</label>
                    </div>
                    @if (! $todasEmpresas)
                    <div class="mb-3">
                        <label class="form-label">Empresas <span class="text-danger">*</span></label>
                        <select class="form-select @error('tenantsSelecionados') is-invalid @enderror"
                                wire:model="tenantsSelecionados" multiple size="5">
                            @foreach ($this->empresasDisponiveis as $empresa)
                            <option value="{{ $empresa->id }}">{{ $empresa->name }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Segure Ctrl (ou Cmd) pra selecionar mais de uma.</small>
                        @error('tenantsSelecionados')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" wire:click="$set('modalAberto', false)">Cancelar</button>
                    <button class="btn btn-primary" wire:click="salvar">
                        <i class="bx bx-check me-1"></i>Salvar
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

@script
<script>
    let quillAviso = null;

    function inserirImagemNoEditorAviso() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = () => {
            const arquivo = input.files[0];
            if (!arquivo) return;

            const range = quillAviso.getSelection(true) ?? { index: quillAviso.getLength() };

            $wire.upload('imagemTemporaria', arquivo, () => {
                $wire.call('processarImagemAviso').then((url) => {
                    quillAviso.insertEmbed(range.index, 'image', url, 'user');
                    quillAviso.setSelection(range.index + 1);
                }).catch(() => {
                    if (typeof toastr !== 'undefined') {
                        toastr.error('Não foi possível enviar a imagem.');
                    }
                });
            }, () => {
                if (typeof toastr !== 'undefined') {
                    toastr.error('Falha no upload da imagem.');
                }
            });
        };
        input.click();
    }

    $wire.on('abrir-editor-aviso', ({ mensagem }) => {
        const container = document.getElementById('quillAvisoMensagem');
        if (!container) return;

        container.innerHTML = '';
        quillAviso = new Quill(container, {
            theme: 'snow',
            placeholder: 'Escreva a mensagem do aviso...',
            modules: {
                toolbar: {
                    container: [
                        ['bold', 'italic', 'underline'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['link', 'image'],
                        ['clean'],
                    ],
                    handlers: { image: inserirImagemNoEditorAviso },
                },
            },
        });
        quillAviso.root.innerHTML = mensagem || '';

        const campoOculto = document.getElementById('avisoMensagemHidden');
        quillAviso.on('text-change', () => {
            campoOculto.value = quillAviso.root.innerHTML;
            campoOculto.dispatchEvent(new Event('input'));
        });
    });

    $wire.on('show-toast', ({ message }) => {
        if (typeof toastr !== 'undefined') {
            toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
            toastr.success(message);
        }
    });
</script>
@endscript
