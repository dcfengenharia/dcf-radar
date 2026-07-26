{{--
    Configuração compartilhada dos gráficos Chart.js do Report Semanal —
    incluído tanto pela página de detalhe (⚡relatorio-detalhe.blade.php)
    quanto pela prévia do assistente (⚡relatorio-novo.blade.php), pra não
    duplicar o objeto de opções/cores/plugins em dois lugares.

    Limiares do velocímetro de aderência (zonas vermelha/amarela/verde)
    espelham as constantes ADERENCIA_LIMIAR_OTIMO/ADERENCIA_LIMIAR_ATENCAO
    no PHP de ⚡relatorio-detalhe.blade.php — mudar um lado exige mudar o
    outro. Escala do velocímetro: 0–100%.

    IMPORTANTE sobre altura dos gráficos: todo canvas Chart.js responsivo
    precisa ficar dentro de um contêiner com ALTURA FIXA definida via CSS
    (não só o atributo height do <canvas>) e usar maintainAspectRatio:false
    — senão o Chart.js entra num loop de redimensionamento e o gráfico
    cresce sem parar. Ver uso em ⚡relatorio-detalhe.blade.php/
    ⚡relatorio-novo.blade.php: sempre `<div style="height: XXXpx">`.
--}}
<script>
    window.RelatorioGraficoConfig = {
        cores: {
            previsto:  { barra: 'rgba(80, 98, 118, 0.2)', linha: '#3C79E8' },
            tendencia: { barra: '#ffd591', linha: '#ffab00' },
            realizado: { barra: '#a8eeb9', linha: '#71dd37' },
        },

        // Paleta rotativa pra gráficos com N categorias dinâmicas (ex:
        // % de HH por disciplina) — sem correspondência fixa
        // categoria->cor como em `cores` acima, só uma sequência que
        // repete se houver mais categorias que cores.
        paletaCategorias: [
            '#696cff', '#71dd37', '#ffab00', '#ff3e1d', '#03c3ec',
            '#8592a3', '#a5a8f5', '#a8eeb9', '#ffd591', '#ffb1ac',
        ],

        /**
         * Monta os 6 datasets (3 barras de % do período no eixo esquerdo +
         * 3 linhas de % acumulado no eixo direito). As DUAS escalas são em
         * %, só que uma pro dado mensal (período) e outra pro acumulado —
         * nunca misturar HH com %, mas os dois eixos continuam existindo.
         */
        construirDatasets(barras, linhas) {
            const cores = this.cores;
            const ordem = ['previsto', 'tendencia', 'realizado'];

            const datasetsBarra = ordem.map((chave) => ({
                type: 'bar',
                label: barras[chave].label,
                data: barras[chave].data,
                backgroundColor: cores[chave].barra,
                yAxisID: 'yMensal',
                order: 2,
            }));

            const datasetsLinha = ordem.map((chave) => ({
                type: 'line',
                label: linhas[chave].label + ' (acum.)',
                data: linhas[chave].data,
                borderColor: cores[chave].linha,
                backgroundColor: cores[chave].linha,
                borderDash: chave === 'tendencia' ? [6, 4] : [],
                spanGaps: true,
                tension: 0.3,
                yAxisID: 'yAcumulado',
                order: 1,
            }));

            return [...datasetsBarra, ...datasetsLinha];
        },

        /** Eixo esquerdo (% do período/mensal, escala automática) + eixo direito (% acumulado, fixo 0-100%). */
        opcoesDuploEixo() {
            return {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { top: 8, right: 8 },
                },
                interaction: { mode: 'index', intersect: false },
                scales: {
                    yMensal: {
                        type: 'linear',
                        position: 'left',
                        min: 0,
                        ticks: { callback: (v) => v + '%' },
                        title: { display: true, text: '% do período' },
                    },
                    yAcumulado: {
                        type: 'linear',
                        position: 'right',
                        min: 0,
                        max: 100,
                        grid: { drawOnChartArea: false },
                        ticks: { callback: (v) => v + '%' },
                        title: { display: true, text: '% acumulado' },
                    },
                },
                plugins: { legend: { position: 'bottom' } },
            };
        },

        /** Doughnut meio-círculo (velocímetro) com 3 zonas coloridas — base do gráfico de aderência. */
        gaugeConfig(limiarAtencao, limiarOtimo, escalaMax) {
            const zonaVermelha = limiarAtencao;
            const zonaAmarela = limiarOtimo - limiarAtencao;
            const zonaVerde = escalaMax - limiarOtimo;

            return {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [zonaVermelha, zonaAmarela, zonaVerde],
                        backgroundColor: ['#ff6b6b', '#ffd166', '#51cf66'],
                        borderWidth: 0,
                        needleValue: 0,
                        scaleMax: escalaMax,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    circumference: 180,
                    rotation: -90,
                    cutout: '70%',
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                },
            };
        },

        /**
         * Agulha do velocímetro. Ângulo lido DIRETO dos arcos já desenhados
         * pelo Chart.js (meta.data[i].startAngle/endAngle, em radianos, na
         * mesma convenção do ctx.arc()/ctx.rotate() do canvas) — em vez de
         * recalcular a partir de options.rotation/circumference (que
         * causou o bug anterior: não dá pra saber com certeza se o
         * Chart.js normaliza esses valores pra radianos internamente antes
         * de expor em chart.options, então reconverter de grau pra radiano
         * "às cegas" podia desalinhar a agulha). Lendo startAngle/endAngle
         * direto dos elementos já renderizados, a agulha SEMPRE bate com o
         * que está desenhado na tela, não importa a configuração.
         */
        pluginAgulha: {
            id: 'agulhaVelocimetro',
            afterDatasetsDraw(chart) {
                const dataset = chart.data.datasets[0];
                const meta = chart.getDatasetMeta(0);
                if (!meta.data.length) return;

                const { ctx } = chart;
                const { x: xCentro, y: yCentro, outerRadius } = meta.data[0];
                const valor = Math.max(0, Math.min(dataset.scaleMax, dataset.needleValue ?? 0));

                const anguloInicio = meta.data[0].startAngle;
                const anguloFim = meta.data[meta.data.length - 1].endAngle;
                const angulo = anguloInicio + (valor / dataset.scaleMax) * (anguloFim - anguloInicio);

                // Desenha a agulha apontando ao longo do eixo +x local (que
                // corresponde a ângulo=0 na mesma convenção de ctx.arc()),
                // depois rotaciona pelo ângulo calculado acima.
                ctx.save();
                ctx.translate(xCentro, yCentro);
                ctx.rotate(angulo);
                ctx.beginPath();
                ctx.moveTo(0, -3);
                ctx.lineTo(outerRadius * 0.85, 0);
                ctx.lineTo(0, 3);
                ctx.fillStyle = '#37424a';
                ctx.fill();
                ctx.restore();

                ctx.save();
                ctx.beginPath();
                ctx.arc(xCentro, yCentro, 6, 0, Math.PI * 2);
                ctx.fillStyle = '#37424a';
                ctx.fill();
                ctx.restore();
            },
        },

        /** Texto "XX% aderência" no centro do velocímetro. */
        pluginTextoCentral: {
            id: 'textoCentralVelocimetro',
            afterDraw(chart) {
                const dataset = chart.data.datasets[0];
                const meta = chart.getDatasetMeta(0);
                if (!meta.data.length) return;

                const { ctx } = chart;
                const { x, y } = meta.data[0];
                const valor = dataset.needleValue;

                ctx.save();
                ctx.textAlign = 'center';
                ctx.fillStyle = '#37424a';
                ctx.font = 'bold 26px sans-serif';
                ctx.fillText(valor === null ? '—' : `${Math.round(valor)}%`, x, y - 6);
                ctx.font = '12px sans-serif';
                ctx.fillStyle = '#8592a3';
                ctx.fillText('aderência', x, y + 16);
                ctx.restore();
            },
        },
    };
</script>
