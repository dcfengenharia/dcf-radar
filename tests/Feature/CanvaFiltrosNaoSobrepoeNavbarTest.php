<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Bug de teste manual, 2026-09-02 — o painel lateral "Filtros" (`.canva-
 * filtros-*`, mesmo padrão copiado em 8 páginas) usava `top: 0; height:
 * 100%;` com `z-index: 1080` — maior que o da navbar fixa
 * (`.layout-navbar`, z-index 1075). Como o painel cobre TODA a altura da
 * viewport (inclusive a faixa da navbar) e fica ACIMA dela na pilha de
 * empilhamento, qualquer clique no sino de notificações/avatar/app-grid
 * era engolido pelo painel enquanto ele estivesse aberto — confirmado ao
 * vivo com `document.elementFromPoint()` retornando o cabeçalho do
 * painel em vez do ícone da navbar.
 *
 * Não é possível reproduzir isso com um teste Feature comum — o bug é
 * puramente de layout CSS (position/z-index/stacking context), e este
 * projeto não tem infraestrutura de teste em browser real (Dusk/
 * Playwright/Cypress). O teste abaixo é uma GUARDA estrutural (grep sobre
 * o Blade compilado), não uma reprodução do bug em si: garante que o
 * padrão CORRIGIDO (`top` deslocado pela altura da navbar) está presente
 * e que o padrão QUEBRADO (`top: 0` combinado com `height: 100%` no
 * mesmo seletor `.canva-filtros-*`) nunca reaparece nos 8 arquivos.
 */
class CanvaFiltrosNaoSobrepoeNavbarTest extends TestCase
{
    private const ARQUIVOS = [
        'resources/views/pages/gestao/⚡minhas-obras.blade.php',
        'resources/views/pages/radar/⚡curvas.blade.php',
        'resources/views/pages/radar/⚡linhas-base.blade.php',
        'resources/views/pages/radar/⚡lookahead.blade.php',
        'resources/views/pages/radar/⚡plano-semanal.blade.php',
        'resources/views/pages/radar/⚡relatorios-restricoes.blade.php',
        'resources/views/pages/radar/⚡restricoes.blade.php',
        'resources/views/pages/radar/⚡suprimentos.blade.php',
    ];

    public function test_todos_os_8_canvas_de_filtro_comecam_abaixo_da_navbar_fixa(): void
    {
        foreach (self::ARQUIVOS as $arquivo) {
            $caminho = base_path($arquivo);
            $this->assertFileExists($caminho, "arquivo esperado não encontrado: {$arquivo}");

            $conteudo = file_get_contents($caminho);

            // Extrai só o bloco de regra .canva-filtros-<algo> { ... } que
            // define position:fixed (o painel em si, não .canva-filtros-body/
            // -aba/-aba-badge, que são outras regras do mesmo arquivo).
            $encontrouRegraDoPainel = preg_match(
                '/\.canva-filtros-[a-z-]+\s*\{[^}]*position:\s*fixed;[^}]*\}/s',
                $conteudo,
                $match
            );
            $this->assertSame(1, $encontrouRegraDoPainel, "regra .canva-filtros-* {{position:fixed}} não encontrada em {$arquivo}");

            $blocoPainel = $match[0];

            $this->assertStringContainsString(
                'top: 3.875rem;',
                $blocoPainel,
                "{$arquivo}: o painel de filtros precisa começar abaixo da navbar fixa (top: 3.875rem, = \$navbar-height do tema) — nunca top: 0, que cobre a faixa da navbar e engole cliques no sino/perfil/app-grid enquanto o painel está aberto."
            );

            $this->assertStringContainsString(
                'height: calc(100% - 3.875rem);',
                $blocoPainel,
                "{$arquivo}: a altura do painel precisa descontar a altura da navbar (calc(100% - 3.875rem)) — nunca height: 100% sozinho, que voltaria a estender o painel por cima da navbar."
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\.canva-filtros-[a-z-]+\s*\{[^}]*top:\s*0;[^}]*height:\s*100%;/s',
                $conteudo,
                "{$arquivo}: reapareceu o padrão quebrado (top: 0 + height: 100% no mesmo seletor de painel) que causava o painel cobrir a navbar fixa."
            );
        }
    }
}
