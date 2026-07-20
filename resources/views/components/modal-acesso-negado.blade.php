{{--
    Popup de acesso negado — substitui a página de erro 403 crua do
    Laravel por um modal. Cobre os dois casos:
    1. Ação Livewire (wire:click) que falha com 403: o hook JS abaixo
       intercepta a requisição antes do Livewire renderizar seu próprio
       erro.
    2. Navegação de página cheia sem permissão: App\Exceptions\Handler::render()
       redireciona de volta com session('flash.popup', 'acesso-negado'),
       e este componente se abre sozinho ao carregar a página.
--}}
<div class="modal fade" id="modalAcessoNegado" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-3 p-md-4">
            <div class="modal-body">
                <div class="mb-3" style="font-size: 3rem; line-height: 1;">🔒</div>
                <h5 class="mb-2">Opa, essa área não é pra você (ainda)!</h5>
                <p class="text-muted mb-4">
                    Você não tem permissão pra acessar isso. Se acha que
                    deveria ter, chama quem administra sua empresa aqui
                    no sistema.
                </p>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi</button>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        function abrirModalAcessoNegado() {
            var el = document.getElementById('modalAcessoNegado');
            if (!el || typeof bootstrap === 'undefined') return;
            bootstrap.Modal.getOrCreateInstance(el).show();
        }

        @if(session('flash.popup') === 'acesso-negado')
            document.addEventListener('DOMContentLoaded', abrirModalAcessoNegado);
        @endif

        document.addEventListener('livewire:init', function () {
            Livewire.hook('request', function ({ fail }) {
                fail(function ({ status, preventDefault }) {
                    if (status === 403) {
                        preventDefault();
                        abrirModalAcessoNegado();
                    }
                });
            });
        });
    })();
</script>
