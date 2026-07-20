{{-- Trava contra clique duplo em wire:navigate: clicar em dois links do menu
     em sequência rápida (antes da primeira navegação terminar) dispara duas
     requisições de navegação sobrepostas. O morph da segunda aplica em cima
     de um DOM que a primeira ainda está desmontando, o componente Livewire
     antigo não é destruído a tempo, e o binding Alpine de algum wire:model
     fica órfão, vazando o código-fonte da closure interna do entangle
     (dataGet/dataSet/shouldSendNetwork) como texto literal dentro do input —
     além de filtros pararem de responder (wire:model.live aponta pro
     componente errado/morto). Investigado no vendor/livewire/livewire/dist/
     livewire.js: o clique real do usuário dispara a navegação a partir do
     'mousedown' (não do 'click'), e não existe nenhuma trava interna contra
     mousedown repetido — então interceptar 'click' via JS (tentado antes,
     não funcionou) chega tarde demais. pointer-events:none nos próprios
     links resolve na raiz: o navegador nem entrega o mousedown/click pra eles
     enquanto uma navegação está em andamento, então não importa qual evento
     o Livewire escuta internamente. --}}
<style>
body.radar-navegando a[wire\:navigate] { pointer-events: none; }
</style>
<script>
document.addEventListener('livewire:navigating', function () { document.body.classList.add('radar-navegando'); });
document.addEventListener('livewire:navigated', function () { document.body.classList.remove('radar-navegando'); });
</script>

<!-- BEGIN: Vendor JS-->
<script src="{{ asset(mix('assets/vendor/libs/jquery/jquery.js')) }}"></script>
<script src="{{ asset(mix('assets/vendor/libs/popper/popper.js')) }}"></script>
<script src="{{ asset(mix('assets/vendor/js/bootstrap.js')) }}"></script>
<script src="{{ asset(mix('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js')) }}"></script>
<script src="{{ asset(mix('assets/vendor/libs/hammer/hammer.js')) }}"></script>
<script src="{{ asset(mix('assets/vendor/libs/typeahead-js/typeahead.js')) }}"></script>
<script src="{{ asset(mix('assets/vendor/js/menu.js')) }}"></script>
{{-- toastr carregado globalmente (ver styles.blade.php para o motivo) --}}
<script src="{{ asset(mix('assets/vendor/libs/toastr/toastr.js')) }}"></script>
@yield('vendor-script')
<!-- END: Page Vendor JS-->
<!-- BEGIN: Theme JS-->
<script src="{{ asset(mix('assets/js/main.js')) }}"></script>

<!-- END: Theme JS-->
<!-- Pricing Modal JS-->
@stack('pricing-script')
<!-- END: Pricing Modal JS-->
<!-- BEGIN: Page JS-->
@yield('page-script')
<!-- END: Page JS-->

@stack('modals')
@livewireScripts
<script src="{{ asset(mix('js/app.js')) }}"></script>

{{-- wire:navigate: atualiza classes do menu lateral sem re-renderizá-lo --}}
<script>
(function () {
    function syncMenuActive() {
        var path = window.location.pathname;
        var items = document.querySelectorAll('#layout-menu .menu-item');

        // Limpa todas as marcações
        items.forEach(function (li) {
            li.classList.remove('active', 'open');
        });

        // Marca o item correspondente à URL atual
        document.querySelectorAll('#layout-menu .menu-link').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href || href === '#' || href.startsWith('javascript:')) return;

            try {
                var linkPath = new URL(href, window.location.origin).pathname;
                // Normaliza: remove trailing slash exceto raiz
                if (linkPath !== '/' && linkPath.endsWith('/')) linkPath = linkPath.slice(0, -1);
                var normPath = path !== '/' && path.endsWith('/') ? path.slice(0, -1) : path;

                if (normPath === linkPath || (linkPath !== '/' && normPath.startsWith(linkPath + '/'))) {
                    var li = link.closest('.menu-item');
                    if (!li) return;
                    li.classList.add('active');

                    // Abre todos os ancestrais (para submenus)
                    var parent = li.parentElement && li.parentElement.closest('.menu-item');
                    while (parent) {
                        parent.classList.add('active', 'open');
                        parent = parent.parentElement && parent.parentElement.closest('.menu-item');
                    }
                }
            } catch (e) { /* URL inválida */ }
        });
    }

    // Roda após cada navegação wire:navigate
    document.addEventListener('livewire:navigated', syncMenuActive);
})();
</script>

{{-- Rede de segurança contra backdrop de modal Bootstrap travado: se algum
     modal (ex.: Criar Empresa, Aviso da Plataforma) ficar aberto quando uma
     navegação wire:navigate começa, o componente pode ser destruído pelo
     morph antes do bootstrap.Modal.hide() rodar de verdade — o
     .modal-backdrop (injetado como irmão solto em <body>, fora da árvore
     rastreada pelo componente) e a classe modal-open ficam presos, cobrindo
     a tela inteira e bloqueando clique em QUALQUER coisa, navbar inclusive
     (mesmo sintoma já visto e corrigido uma vez no componente radar-loading —
     ver feedback_persist_componentes_globais_livewire). Isso limpa
     incondicionalmente no início de toda navegação, então nunca atrapalha um
     modal que devia mesmo estar fechado. --}}
<script>
document.addEventListener('livewire:navigating', function () {
    document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
});
</script>

