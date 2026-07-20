{{--
  ============================================================
  Indicador de Loading Global do DCF Radar
  ============================================================
  Aparece automaticamente em QUALQUER operação Livewire.
  É um componente Blade puro — não depende de wire:loading.

  COMO PERSONALIZAR:
  • Troque as $mensagens pelo que quiser exibir
  • Ajuste $intervaloMs (milissegundos entre mensagens)
  • Mude o $icone por qualquer classe BoxIcons (bx-*)
  • Altere as cores no style inline (--radar-color)

  IMPORTANTE: este componente PRECISA ficar dentro de @persist(...) no
  layout (ver contentNavbarLayout.blade.php) — sem isso, o wire:navigate
  recria o elemento a cada navegação e o `document.addEventListener` do
  x-init abaixo vaza um listener novo por navegação, além de correr o
  risco de um evento de resposta chegar depois que a instância antiga já
  foi destruída, deixando o overlay full-screen (z-index 99999) travado
  visível pra sempre — sintoma real: navbar "trava" e itens somem por
  trás do overlay escurecido. O listener de 'livewire:navigating' é uma
  segunda proteção: garante que o overlay nunca sobrevive a uma troca de
  página, mesmo que algum request perca seu evento de conclusão.
  ============================================================
--}}

@php
// ──── PERSONALIZE AQUI ────────────────────────────────────
$mensagens = [
    '🏗️  Consultando o canteiro...',
    '📋  Verificando restrições...',
    '⚠️  Calculando riscos...',
    '🔩  Apertando os parafusos...',
    '📐  Alinhando os dados...',
    '🪖  Checando a segurança...',
    '📊  Atualizando o Radar...',
    '🔍  Inspecionando registros...',
    '✅  Quase pronto...',
    '🧱  Removendo obstáculos...',
];
$icone       = 'bx bx-hard-hat';
$intervaloMs = 2000;
$corPrimaria = '#696cff';
$corSecundaria = '#9c27b0';
// ──────────────────────────────────────────────────────────
@endphp

<div
    id="radar-global-loading"
    x-data="{
        visible: false,
        frases: {{ json_encode($mensagens) }},
        idx: 0,
        timer: null,
        start() {
            this.visible = true;
            this.idx = Math.floor(Math.random() * this.frases.length);
            this.timer = setInterval(() => {
                this.idx = (this.idx + 1) % this.frases.length;
            }, {{ $intervaloMs }});
        },
        stop() {
            this.visible = false;
            clearInterval(this.timer);
            this.timer = null;
        }
    }"
    x-init="
        document.addEventListener('livewire:request',    () => start());
        document.addEventListener('livewire:response',   () => stop());
        document.addEventListener('livewire:commit',     () => stop());
        document.addEventListener('livewire:navigating', () => stop());
    "
    x-show="visible"
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    style="
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        z-index: 99999;
        background: rgba(24, 36, 51, 0.50);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        align-items: center;
        justify-content: center;
    "
>
    <div style="
        background: #fff;
        border-radius: 20px;
        padding: 36px 44px;
        box-shadow: 0 24px 64px rgba(0,0,0,0.25);
        text-align: center;
        min-width: 280px;
        max-width: 360px;
        border-top: 5px solid {{ $corPrimaria }};
    ">

        {{-- Ícone com animação de rotação --}}
        <div style="margin-bottom: 20px; position: relative; display: inline-block;">
            <span style="
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 62px; height: 62px;
                border-radius: 50%;
                background: linear-gradient(135deg, {{ $corPrimaria }} 0%, {{ $corSecundaria }} 100%);
                box-shadow: 0 8px 24px rgba(105, 108, 255, 0.35);
                animation: dcf-spin 1.6s linear infinite;
            ">
                <i class="{{ $icone }}" style="font-size: 28px; color: #fff;"></i>
            </span>
            {{-- Pulso ao redor --}}
            <span style="
                position: absolute;
                top: 50%; left: 50%;
                transform: translate(-50%, -50%);
                width: 62px; height: 62px;
                border-radius: 50%;
                background: {{ $corPrimaria }};
                opacity: 0.15;
                animation: dcf-pulse 1.6s ease-out infinite;
            "></span>
        </div>

        {{-- Barra de progresso indeterminada --}}
        <div style="
            height: 4px;
            background: #f0f1ff;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 20px;
        ">
            <div style="
                height: 100%;
                width: 40%;
                background: linear-gradient(90deg, {{ $corPrimaria }}, {{ $corSecundaria }});
                border-radius: 3px;
                animation: dcf-bar 1.6s ease-in-out infinite;
            "></div>
        </div>

        {{-- Mensagem rotativa --}}
        <p
            x-text="frases[idx]"
            style="
                font-size: 14px;
                color: #566a7f;
                margin: 0;
                min-height: 24px;
                font-weight: 500;
            "
        ></p>

        <small style="
            display: block;
            margin-top: 10px;
            color: #c8cfe0;
            font-size: 10px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        ">DCF · Radar</small>
    </div>
</div>

<style>
@keyframes dcf-spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
@keyframes dcf-pulse {
    0%   { transform: translate(-50%,-50%) scale(1);   opacity: .18; }
    70%  { transform: translate(-50%,-50%) scale(1.8); opacity: 0;   }
    100% { transform: translate(-50%,-50%) scale(1.8); opacity: 0;   }
}
@keyframes dcf-bar {
    0%   { transform: translateX(-100%); }
    50%  { transform: translateX(150%); }
    100% { transform: translateX(350%); }
}
</style>
