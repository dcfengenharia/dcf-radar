{{--
  Modal de confirmação genérico e reutilizável — substitui wire:confirm
  (que dispara o confirm() nativo do navegador) por um popup Bootstrap
  consistente com o resto do sistema.

  Qualquer botão que hoje usa wire:click="metodo(args)" wire:confirm="msg"
  vira:

      <button type="button"
              onclick="confirmarAcao(this, {
                  mensagem: 'Remover X?',
                  metodo: 'excluir',
                  args: ['{{ $id }}'],
                  corBotao: 'danger',
                  icone: 'bx-trash',
              })">

  window.confirmarAcao resolve o componente Livewire mais próximo do botão
  clicado (via wire:id do ancestral) e chama o método nele mesmo, depois de
  confirmado no popup — mesma coisa que wire:click fazia, só que só dispara
  após o clique em "Confirmar" deste modal em vez do confirm() do browser.

  Este componente é registrado UMA VEZ nos layouts (contentNavbarLayout.
  blade.php / layoutAdmin.blade.php), dentro de @persist — mesmo motivo já
  documentado em radar-loading.blade.php: sem @persist, wire:navigate
  recriaria o modal (e os ids ficariam desincronizados) a cada navegação.
--}}
<div class="modal fade" id="confirmacaoAcaoModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-semibold">
                    <i class="bx bx-help-circle me-2" id="confirmacaoAcaoIcone"></i>
                    <span id="confirmacaoAcaoTitulo">Confirmar ação</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-0" id="confirmacaoAcaoMensagem"></p>
            </div>
            <div class="modal-footer border-top py-3">
                <button type="button" class="btn btn-label-secondary shadow-none" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn shadow-none" id="confirmacaoAcaoBtnConfirmar" onclick="window.__confirmacaoAcaoExecutar()">
                    <span id="confirmacaoAcaoBtnTexto">Confirmar</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    window.__confirmacaoAcaoPendente = null;

    window.confirmarAcao = function (btn, opts) {
        const {
            titulo = 'Confirmar ação',
            mensagem,
            corBotao = 'danger',
            textoBotao = 'Confirmar',
            icone = 'bx-help-circle',
            metodo,
            args = [],
        } = opts;

        const root = btn.closest('[wire\\:id]');
        window.__confirmacaoAcaoPendente = {
            componentId: root ? root.getAttribute('wire:id') : null,
            metodo,
            args,
        };

        document.getElementById('confirmacaoAcaoTitulo').textContent = titulo;
        document.getElementById('confirmacaoAcaoMensagem').textContent = mensagem;
        document.getElementById('confirmacaoAcaoIcone').className = 'bx ' + icone + ' me-2';

        const btnConfirmar = document.getElementById('confirmacaoAcaoBtnConfirmar');
        btnConfirmar.className = 'btn btn-' + corBotao + ' shadow-none';
        document.getElementById('confirmacaoAcaoBtnTexto').textContent = textoBotao;

        bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmacaoAcaoModal')).show();
    };

    window.__confirmacaoAcaoExecutar = function () {
        const pendente = window.__confirmacaoAcaoPendente;
        const modalEl = document.getElementById('confirmacaoAcaoModal');

        if (pendente?.componentId && window.Livewire) {
            const component = window.Livewire.find(pendente.componentId);
            component?.call(pendente.metodo, ...pendente.args);
        }

        window.__confirmacaoAcaoPendente = null;
        bootstrap.Modal.getInstance(modalEl)?.hide();
        setTimeout(() => {
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove());
        }, 150);
    };
</script>
