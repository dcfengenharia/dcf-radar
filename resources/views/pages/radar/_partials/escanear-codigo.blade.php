{{--
    Ciclo 20, Etapa 20.8 — componente reutilizável "Escanear código"
    (Seção 28 do pedido: "não espalhar implementação JS em 5 telas
    diferentes"). Um único partial, incluído com parâmetros diferentes em
    cada modal.

    Parâmetros:
    - $campoAlvo: nome da propriedade Livewire pública a preencher
      (ex.: 'saidaLocalId') — ignorado quando $metodoCustom é passado.
    - $campoUnidadeAlvo (opcional): quando informado, o código pode
      resolver TANTO um Material QUANTO uma Unidade — se for Unidade,
      $campoAlvo recebe o material_id dela E $campoUnidadeAlvo recebe o
      próprio id (usado no par "Material/Lote-Bobina-Serial" dos modais
      de Saída/Transferência/Reserva).
    - $tipoEsperado: 'material'|'unidade'|'local'|null — valida o tipo
      resolvido antes de preencher (ignorado quando $campoUnidadeAlvo é
      usado, que já aceita material OU unidade).
    - $label: texto do placeholder/rótulo (ex.: "Local").
    - $metodoCustom (opcional): nome de um método Livewire alternativo a
      chamar em vez de resolverEAplicarScan/resolverEAplicarScanMaterialOuUnidade
      (usado só pelo Inventário, que precisa de lógica própria — Seção 20).

    **Scanner USB/Bluetooth (Seção 12)**: funciona OUT-OF-THE-BOX — o
    scanner físico só digita o código no campo focado e envia Enter,
    exatamente como um teclado. Nenhum JS especial precisa "detectar" um
    scanner — o mesmo listener de Enter cobre digitação manual E scanner.

    **Câmera (Seção 13, decisão do usuário)**: só a API nativa
    `BarcodeDetector` do navegador — zero dependência nova. Quando
    `window.BarcodeDetector` não existe (Safari/iOS, Firefox), o botão de
    câmera simplesmente não aparece (Alpine `x-show`) — o campo manual/
    scanner continua funcionando normalmente.

    **Duplo scan (Seção 24)**: nunca dispara nenhuma Action — só chama
    um método que ATRIBUI valor a propriedades (idempotente por
    natureza: escanear o mesmo código 2x só atribui o mesmo valor 2x,
    nunca duplica um efeito colateral).
--}}
@php
    $tipoEsperadoJs = $tipoEsperado ?? '';
@endphp
<div
    x-data="{
        codigo: '',
        cameraAberta: false,
        cameraSuportada: typeof window.BarcodeDetector !== 'undefined',
        stream: null,
        detector: null,
        intervalo: null,
        processar() {
            if (! this.codigo.trim()) { return; }
            @if ($metodoCustom ?? null)
                $wire.call('{{ $metodoCustom }}', this.codigo.trim());
            @elseif ($campoUnidadeAlvo ?? null)
                $wire.call('resolverEAplicarScanMaterialOuUnidade', this.codigo.trim(), '{{ $campoAlvo }}', '{{ $campoUnidadeAlvo }}');
            @else
                $wire.call('resolverEAplicarScan', this.codigo.trim(), '{{ $campoAlvo }}', {{ $tipoEsperadoJs ? "'{$tipoEsperadoJs}'" : 'null' }});
            @endif
            this.codigo = '';
        },
        async abrirCamera() {
            if (! this.cameraSuportada) { return; }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                this.$refs.video.srcObject = this.stream;
                this.cameraAberta = true;
                this.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                this.intervalo = setInterval(async () => {
                    try {
                        const codigos = await this.detector.detect(this.$refs.video);
                        if (codigos.length > 0) {
                            this.codigo = codigos[0].rawValue;
                            this.fecharCamera();
                            this.processar();
                        }
                    } catch (e) { /* frame ilegível, tenta de novo no próximo intervalo */ }
                }, 400);
            } catch (e) {
                this.cameraAberta = false;
            }
        },
        fecharCamera() {
            if (this.intervalo) { clearInterval(this.intervalo); this.intervalo = null; }
            if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
            this.cameraAberta = false;
        }
    }"
    x-on:destroy="fecharCamera()"
    class="mb-2"
>
    <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bx bx-barcode-reader"></i></span>
        <input
            type="text"
            class="form-control"
            placeholder="Escanear ou digitar código de {{ $label }}"
            x-model="codigo"
            x-on:keydown.enter.prevent="processar()"
        >
        <button type="button" class="btn btn-outline-secondary" x-show="cameraSuportada && ! cameraAberta" x-on:click="abrirCamera()" title="Usar câmera">
            <i class="bx bx-camera"></i>
        </button>
        <button type="button" class="btn btn-outline-danger" x-show="cameraAberta" x-on:click="fecharCamera()" title="Fechar câmera">
            <i class="bx bx-x"></i>
        </button>
        <button type="button" class="btn btn-outline-primary" x-on:click="processar()">Resolver</button>
    </div>
    <div x-show="cameraAberta" class="mt-2" style="max-width: 320px;">
        <video x-ref="video" autoplay playsinline muted style="width: 100%; border-radius: 4px;"></video>
    </div>
</div>
