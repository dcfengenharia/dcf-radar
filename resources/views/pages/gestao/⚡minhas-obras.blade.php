<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use App\Models\Work;
use App\Models\Tenant;
use App\Exports\ObrasExport;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

new class extends Component {
  use WithPagination;
  protected $paginationTheme = 'bootstrap';

  public ?Tenant $tenant = null;
  public string $activeTab = 'todas';
  public string $search = '';

  public function mount(): void
  {
    $this->tenant = Tenant::find(\App\Support\TenantContext::currentId());
  }

  public function setTab(string $tab): void
  {
    $this->activeTab = $tab;
    $this->resetPage();
    $this->search = '';
  }

  private function worksBaseQuery(): \Illuminate\Database\Eloquent\Builder
  {
    return Work::with(['client', 'users' => fn ($q) => $q->select('users.id', 'users.first_name', 'users.last_name', 'users.profile_photo_path')])
      ->withCount('users')
      ->addSelect([
      'works.*',
      'avanco_realizado' => \App\Models\ReportCurvaDatapoint::select('percentual_acumulado')
        ->join('report_curvas', 'report_curva_datapoints.report_curva_id', '=', 'report_curvas.id')
        ->join('reports', 'report_curvas.report_id', '=', 'reports.id')
        ->whereColumn('reports.obra_id', 'works.id')
        ->where('reports.status', 'emitido')
        ->whereNull('report_curvas.pacote_trabalho_id')
        ->where('report_curva_datapoints.serie', 'realizado')
        ->orderByDesc('reports.emitido_em')
        ->orderByDesc('report_curva_datapoints.periodo_inicio')
        ->limit(1),
    ]);
  }

  private function applySearchFilter(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
  {
    if (! $this->search) {
      return $query;
    }

    return $query->where(function ($inner) {
      $inner
        ->where('name', 'like', "%{$this->search}%")
        ->orWhere('location', 'like', "%{$this->search}%")
        ->orWhereHas('client', fn($c) => $c->where('name', 'like', "%{$this->search}%"));
    });
  }

  #[Computed]
  public function allWorks()
  {
    return $this->applySearchFilter($this->worksBaseQuery())
      ->orderBy('name')
      ->paginate(7, pageName: 'page_all');
  }

  #[Computed]
  public function myWorks()
  {
    return $this->applySearchFilter(
      $this->worksBaseQuery()->whereHas('users', fn($q) => $q->where('user_id', Auth::id()))
    )
      ->orderBy('name')
      ->paginate(7, pageName: 'page_my');
  }

  /**
   * Mesma query das abas (worksBaseQuery + filtro de busca), só que sem
   * paginação — garante que a exportação sempre reflete exatamente o que
   * a tela está mostrando (aba ativa + busca), nunca uma lógica separada.
   */
  private function exportQuery(): \Illuminate\Database\Eloquent\Builder
  {
    $query = $this->worksBaseQuery();

    if ($this->activeTab === 'minhas') {
      $query->whereHas('users', fn($q) => $q->where('user_id', Auth::id()));
    }

    return $this->applySearchFilter($query)->orderBy('name');
  }

  public function exportarExcel()
  {
    return Excel::download(new ObrasExport($this->exportQuery()->get()), "obras-{$this->tenant->id}.xlsx");
  }

  public function exportarPdf()
  {
    $pdf = Pdf::loadView('exports.obras-pdf', [
      'tenant' => $this->tenant,
      'works' => $this->exportQuery()->get(),
      'aba' => $this->activeTab === 'todas' ? 'Todas as Obras' : 'Minhas Obras',
    ]);

    return response()->streamDownload(fn() => print $pdf->output(), "obras-{$this->tenant->id}.pdf");
  }

  public function temFiltrosAtivos(): bool
  {
    return $this->search !== '';
  }

  public function updatedSearch(): void
  {
    $this->resetPage();
  }

  public function limparFiltros(): void
  {
    $this->search = '';
    $this->resetPage();
  }

  #[On('work-created')]
  public function onWorkCreated(): void
  {
    $this->resetPage();
  }
};
?>

<div>
    {{-- Cabeçalho --}}
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
        <div>
            <h4 class="mb-1 mt-2">🏗️ Obras do(a) <mark>{{ $tenant->name }}</mark></h4>
            <p class="text-muted mb-0">Visualize todas as obras da sua empresa e veja os detalhes das obras em que você faz parte da equipe.</p>
        </div>
    </div>

    {{-- =========================================================================
         CANVA LATERAL DE FILTROS — mesmo mecanismo já usado em Restrições/
         Lookahead/Plano Semanal/Suprimentos (overlay fixo, aba presa na borda
         direita, nunca o offcanvas nativo do Bootstrap).
         ========================================================================= --}}
    <div class="canva-filtros-minhas-obras" :class="filtrosAbertos ? 'canva-filtros-aberto' : ''" x-data="{ filtrosAbertos: false }">
        <button type="button" class="canva-filtros-aba" @click="filtrosAbertos = true" title="Filtros">
            <i class="bx bx-filter-alt"></i>
            @if($this->temFiltrosAtivos())
            <span class="canva-filtros-aba-badge"></span>
            @endif
        </button>

        <div class="canva-filtros-header d-flex align-items-center justify-content-between border-bottom px-4 py-3">
            <h6 class="mb-0 fw-semibold">
                <i class="bx bx-filter-alt me-1"></i>Filtros
                @if($this->temFiltrosAtivos())
                <span class="badge bg-primary rounded-pill ms-1">ativos</span>
                @endif
            </h6>
            <a href="javascript:void(0)" class="text-body" @click="filtrosAbertos = false">
                <i class="bx bx-x fs-4"></i>
            </a>
        </div>

        <div class="canva-filtros-body px-4 py-3">
            <div class="row g-2">
                <div class="col-12">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bx bx-search"></i></span>
                        <input type="text" class="form-control" placeholder="Buscar por nome, cliente ou localização..."
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>

                @if($this->temFiltrosAtivos())
                <div class="col-12">
                    <button class="btn btn-sm btn-outline-secondary w-100" wire:click="limparFiltros">
                        <i class="bx bx-x me-1"></i>Limpar filtros
                    </button>
                </div>
                @endif

                <div class="col-12"><hr class="my-1"></div>

                <div class="col-12">
                    <button class="btn btn-outline-success btn-sm w-100" wire:click="exportarExcel">
                        <i class="bx bxs-file-export me-1"></i>Excel
                    </button>
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-danger btn-sm w-100" wire:click="exportarPdf">
                        <i class="bx bxs-file-pdf me-1"></i>PDF
                    </button>
                </div>
            </div>
        </div>
    </div>

    @if ($this->allWorks->count() >0)

      {{-- Abas --}}
      <ul class="nav nav-tabs mb-4" role="tablist">
          <li class="nav-item">
              <button
                  class="nav-link {{ $activeTab === 'todas' ? 'active' : '' }}"
                  wire:click="setTab('todas')"
                  type="button"
              >
                  <i class="bx bx-buildings me-1"></i>
                  Todas as Obras
                  <span class="badge bg-secondary ms-1">{{ $this->allWorks->total() }}</span>
              </button>
          </li>
          <li class="nav-item">
              <button
                  class="nav-link {{ $activeTab === 'minhas' ? 'active' : '' }}"
                  wire:click="setTab('minhas')"
                  type="button"
              >
                  <i class="bx bx-hard-hat me-1"></i>
                  Minhas Obras
                  <span class="badge bg-primary ms-1">{{ $this->myWorks->total() }}</span>
              </button>
          </li>
      </ul>

      {{-- Conteúdo da aba ativa --}}
      @php
          if ($activeTab === 'todas') {
              $works = $this->allWorks;
              $emptyMessage = 'Nenhuma obra cadastrada ainda.';
              $pageName = 'page_all';
          } else {
              $works = $this->myWorks;
              $emptyMessage = 'Você ainda não está vinculado a nenhuma obra.';
              $pageName = 'page_my';
          }
      @endphp

      <div
          x-data="{ viewMode: localStorage.getItem('dcf-obras--viewMode') || 'lista' }"
          x-init="$watch('viewMode', v => localStorage.setItem('dcf-obras--viewMode', v))"
      >
          <div class="d-flex justify-content-end mb-3">
              <div class="btn-group" role="group" aria-label="Alternar visualização">
                  <button
                      type="button"
                      class="btn btn-outline-secondary btn-sm"
                      :class="{ 'active': viewMode === 'lista' }"
                      @click="viewMode = 'lista'"
                      title="Ver em lista"
                  >
                      <i class="bx bx-list-ul"></i>
                  </button>
                  <button
                      type="button"
                      class="btn btn-outline-secondary btn-sm"
                      :class="{ 'active': viewMode === 'cards' }"
                      @click="viewMode = 'cards'"
                      title="Ver em cards"
                  >
                      <i class="bx bx-grid-alt"></i>
                  </button>
              </div>
          </div>

          <div x-show="viewMode === 'lista'">
              @include('pages.gestao._partials.obras-table', ['works' => $works, 'emptyMessage' => $emptyMessage, 'pageName' => $pageName])
          </div>
          <div x-show="viewMode === 'cards'" x-cloak>
              @include('pages.gestao._partials.obras-cards', ['works' => $works, 'emptyMessage' => $emptyMessage])
          </div>
      </div>


    @else
      <div class="card-body text-center py-5 my-4">
        <div class="avatar avatar-xl mx-auto mb-4 bg-label-primary p-2 rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
            <i class="bx bx-hard-hat display-4 text-primary"></i>
        </div>
        <h4 class="fw-bold text-heading mb-4">Seu Canteiro Está Vazio!</h4>
        <p class="text-muted mx-auto mb-4 px-3" style="max-width: 480px;">
            Comece cadastrando a primeira obra da sua empresa. <br>
            Cada obra é o ponto de partida para o planejamento bem feito.
        </p>
      </div>
    @endif




<style>
/* Canva lateral de filtros — mesmo mecanismo de Restrições/Lookahead/Plano
   Semanal/Suprimentos: overlay fixo com aba presa na borda direita.
   z-index 1080 fica acima da navbar mas abaixo dos modais Bootstrap (1090+).
   IMPORTANTE: precisa ficar DENTRO da <div> raiz do componente — Livewire só
   renderiza o conteúdo do elemento raiz único; um <style> colocado depois do
   </div> de fechamento simplesmente não chega no DOM do navegador (bug real
   encontrado nesta sessão: a classe .canva-filtros-aberto era aplicada certinho
   via Alpine, mas o CSS que a estilizava nunca existia).
   Bug de teste manual, 2026-09-02 — `top: 0` fazia o canva cobrir também a
   faixa da navbar fixa (0 a 3.875rem, = $navbar-height do tema); como o
   z-index do canva é maior que o da navbar, um clique no sino/perfil/
   app-grid nessa faixa era engolido pelo canva aberto (mesmo bug nos 8
   arquivos que usam este padrão — ver ⚡restricoes.blade.php pro
   diagnóstico completo). Corrigido começando o canva abaixo da navbar. */
.canva-filtros-minhas-obras {
    position: fixed;
    top: 3.875rem;
    right: -360px;
    height: calc(100% - 3.875rem);
    z-index: 1080;
    display: flex;
    flex-direction: column;
    width: 360px;
    max-width: 90vw;
    background: var(--bs-body-bg, #fff);
    box-shadow: 0 0 20px 0 rgba(0, 0, 0, .2);
    transition: right .25s ease-in-out;
}
.canva-filtros-minhas-obras.canva-filtros-aberto { right: 0; }
.canva-filtros-body { flex: 1 1 auto; overflow-y: auto; }
.canva-filtros-aba {
    position: absolute;
    top: 140px;
    left: -42px;
    width: 42px;
    height: 42px;
    border: 0;
    border-top-left-radius: .375rem;
    border-bottom-left-radius: .375rem;
    background: var(--bs-primary);
    color: #fff;
    font-size: 1.1rem;
    box-shadow: -2px 0 8px rgba(0, 0, 0, .15);
    transition: opacity .15s linear;
}
.canva-filtros-minhas-obras.canva-filtros-aberto .canva-filtros-aba { opacity: 0; pointer-events: none; }
.canva-filtros-aba-badge {
    position: absolute; top: 4px; right: 4px; width: 8px; height: 8px;
    border-radius: 50%; background: var(--bs-danger);
}
@media (max-width: 575.98px) {
    .canva-filtros-minhas-obras { width: 300px; right: -300px; }
    .canva-filtros-minhas-obras.canva-filtros-aberto { right: 0; }
}
</style>
</div>

@script
<script>
    $wire.on('show-toast', ({ message }) => {
        toastr.options = { positionClass: 'toast-top-right', timeOut: 4000, closeButton: true, progressBar: true };
        toastr.success(message);
    });
</script>
@endscript
