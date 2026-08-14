{{--
    Explicação didática de como o Score de Saúde é calculado — fonte única
    de texto, reaproveitada onde o Score for exibido (hoje só em
    ⚡importacao-detalhe.blade.php). Puramente informativo/estático, sem
    nenhum valor calculado aqui — a matemática de verdade vive só em
    App\Support\HealthCheck\Score\ScoreCalculator (ver CLAUDE.md, Fase 3).

    Toggle via Alpine puro (x-data + @click), nunca data-bs-toggle="collapse"
    — mesmo motivo já documentado em health-check-findings.blade.php: o
    estado próprio do Bootstrap em JS desincroniza quando o Livewire
    remonta o DOM ao redor.
--}}
<div class="card" x-data="{ aberto: false }">
    <div class="card-body d-flex align-items-center gap-2" style="cursor:pointer" @click="aberto = !aberto">
        <i class="bx bx-help-circle"></i>
        <span class="fw-semibold">Como este Score foi calculado?</span>
        <i class="bx ms-auto" :class="aberto ? 'bx-chevron-up' : 'bx-chevron-down'"></i>
    </div>
    <div x-show="aberto" x-transition x-cloak class="card-body pt-0">
        <ul class="mb-0 ps-3">
            <li>O Score parte de <strong>100 pontos</strong> — um cronograma sem nenhuma inconsistência encontrada fica em 100.</li>
            <li>Cada inconsistência encontrada (finding) reduz pontos conforme a <strong>severidade</strong> — quanto mais grave, maior o peso.</li>
            <li>O quanto uma inconsistência reduz depende também da <strong>quantidade de atividades afetadas</strong>, sempre em <strong>proporção</strong> ao total de atividades elegíveis daquele cronograma — o mesmo problema absoluto pesa menos num cronograma grande do que num pequeno.</li>
            <li>Ocorrências <strong>Informativas nunca reduzem o Score</strong> — elas aparecem na análise só como contexto adicional.</li>
            <li>A <strong>Cobertura</strong> mede quantas das atividades executáveis do cronograma estão ativas e, portanto, elegíveis para as análises de rede (estrutura, lógica, folgas) — uma cobertura abaixo de 100% reduz a confiança na análise, mesmo sem mudar o Score em si.</li>
            <li>Este Score é uma <strong>fotografia desta importação específica</strong> — ele nunca muda depois, mesmo que as regras ou a fórmula evoluam no futuro.</li>
            <li>O objetivo <strong>não é atingir 100 a qualquer custo</strong> — é <strong>reduzir os riscos do cronograma</strong>. Um Score alto não substitui o julgamento do planejador, e um Score abaixo de 100 não significa que o cronograma está errado: use o Mapa de Ações para decidir, com bom senso, o que vale a pena corrigir antes de seguir com o planejamento.</li>
            <li>Uma importação de <strong>Baseline</strong> avalia só as regras de <strong>Planejamento</strong> (estrutura do cronograma, lógica de predecessoras, folgas, duração, baseline) — regras que dependem de dados de execução real (datas reais, HH realizado, % concluído) ainda não fazem sentido aqui, porque a obra ainda não começou a rodar sob esse cronograma. Uma importação de <strong>Avanço</strong> ou <strong>Ambos</strong> avalia Planejamento + Execução juntos.</li>
            <li>Por isso uma dimensão (ex.: Datas, HH, Marcos, Lógica) pode aparecer como <strong>"Parcialmente avaliada"</strong> numa Baseline — ela reúne regras de Planejamento e de Execução ao mesmo tempo, e só a parte de Planejamento roda nesse momento.</li>
            <li><strong>"Não avaliada nesta importação" não significa "saudável" nem "com problema"</strong> — significa que aquelas regras simplesmente não eram aplicáveis a este tipo de importação. O Score exibido reflete só as regras que de fato rodaram.</li>
        </ul>
    </div>
</div>
