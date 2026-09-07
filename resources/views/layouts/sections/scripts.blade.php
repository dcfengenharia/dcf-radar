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
<script data-navigate-once>
document.addEventListener('livewire:navigating', function () { document.body.classList.add('radar-navegando'); });
document.addEventListener('livewire:navigated', function () { document.body.classList.remove('radar-navegando'); });
</script>

{{-- Bug de teste manual, 2026-09-03 — "navbar/dropdowns morrem depois de
     navegar" (sino, avatar, atalhos, tudo). Causa raiz lida direto no
     vendor/livewire/livewire/dist/livewire.js (não por memória de versão
     antiga): `prepNewBodyScriptTagsToRun()` clona e REEXECUTA toda tag
     <script> dentro de <body> a cada wire:navigate, a menos que ela carregue
     `data-navigate-once` — o próprio Livewire já usa esse atributo na sua
     tag de config (`FrontendAssets::scriptConfig()`), é o mecanismo OFICIAL
     pra isso, não um workaround. Sem ele, cada navegação:
     (1) reexecutava bootstrap.js (o bundle real da lib, reexportado como
     window.bootstrap) — cada execução cria uma 2ª instância do módulo com
     seu PRÓPRIO listener delegado de clique em `document` pra
     data-bs-toggle; com 2+ instâncias reagindo ao mesmo clique, a checagem
     de estado (classe .show, síncrona) fecha o que a outra acabou de abrir
     NO MESMO clique — o dropdown "não responde" (provado ao vivo:
     bootstrap.Dropdown.getOrCreateInstance(el).toggle() funciona, um clique
     real não);
     (2) reexecutava resources/assets/js/main.js, que declara `let menu,
     isRtl, ...` fora de qualquer função — `let`/`const`/`class` de topo
     vivem num único escopo de "script" por documento (não um por tag), e a
     2ª execução lançava `Uncaught SyntaxError: Identifier 'menu' has
     already been declared` (confirmado no Console real);
     (3) reexecutava resources/js/app.js, que faz `window.Echo = new
     Echo(...)` — cada navegação abria uma NOVA conexão WebSocket/Reverb
     sem nunca fechar a anterior.
     Nenhum desses arquivos precisa rodar mais de uma vez por sessão — o
     sidebar/menu que main.js inicializa já é @persist('sidebar') (nunca
     destruído), então a correção é só marcar cada script de
     vendor/tema/app como "rode uma vez só", o mecanismo que o próprio
     Livewire usa pra si mesmo. Scripts POR PÁGINA (@yield('vendor-script')/
     @yield('page-script'), Chart.js etc.) ficam de fora de propósito —
     são diferentes a cada página, nunca declaram binding global
     conflitante, e cada um já roda de novo quando a página muda (esperado). --}}
<!-- BEGIN: Vendor JS-->
<script data-navigate-once src="{{ asset(mix('assets/vendor/libs/jquery/jquery.js')) }}"></script>
<script data-navigate-once src="{{ asset(mix('assets/vendor/libs/popper/popper.js')) }}"></script>
<script data-navigate-once src="{{ asset(mix('assets/vendor/js/bootstrap.js')) }}"></script>
<script data-navigate-once src="{{ asset(mix('assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js')) }}"></script>
<script data-navigate-once src="{{ asset(mix('assets/vendor/libs/hammer/hammer.js')) }}"></script>
<script data-navigate-once src="{{ asset(mix('assets/vendor/libs/typeahead-js/typeahead.js')) }}"></script>
<script data-navigate-once src="{{ asset(mix('assets/vendor/js/menu.js')) }}"></script>
{{-- toastr carregado globalmente (ver styles.blade.php para o motivo) --}}
<script data-navigate-once src="{{ asset(mix('assets/vendor/libs/toastr/toastr.js')) }}"></script>
@yield('vendor-script')
<!-- END: Page Vendor JS-->
<!-- BEGIN: Theme JS-->
<script data-navigate-once src="{{ asset(mix('assets/js/main.js')) }}"></script>

<!-- END: Theme JS-->
<!-- Pricing Modal JS-->
@stack('pricing-script')
<!-- END: Pricing Modal JS-->
<!-- BEGIN: Page JS-->
@yield('page-script')
<!-- END: Page JS-->

@stack('modals')
@livewireScripts
<script data-navigate-once src="{{ asset(mix('js/app.js')) }}"></script>

{{-- wire:navigate: atualiza classes do menu lateral sem re-renderizá-lo --}}
<script data-navigate-once>
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
<script data-navigate-once>
document.addEventListener('livewire:navigating', function () {
    document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
});
</script>

