# ONDA 7 — Importação de cronograma + curvas de avanço

Complementa o `BRIEFING-DCF-RADAR.md`. Forma-alvo em
`/referencia/dcf-radar/app/Imports`, `app/Jobs`, `app/Models`.

## Visão

Estrutura (tarefas, EAP, datas, linha de base, caminho crítico) e avanço
físico (HH para as curvas S) vêm do `.xml` (MSPDI). Importar é
sincronização recorrente; cada importação é um snapshot.

Identificação da tarefa: **UID (ID Exclusivo)**.

## Regras de HH validadas contra arquivo real

Testamos a leitura contra um cronograma real (Vopak, ~770 mil HH) e
cravamos três regras — sem elas os números não batem:

1. **Só recurso de Trabalho** (Resource Type = 1). Material/custo/nulo não
   é homem-hora; incluí-lo inflava o total em dezenas de milhares de HH.
2. **Value do trabalho faseado é DURAÇÃO** (`PT..H..M..S`), não minutos.
3. **Distribuição pelo PONTO MÉDIO do bloco** — a regra de menor desvio.

Séries: Previsto = baseline (tipo 4); Realizado = actual (tipo 2);
Tendência = restante + actual (tipo 1 + 2).

## Decisão sobre desvio (assumida)

A reconstrução a partir do XML tem desvio de fronteira **< ~0,3% por mês**
(o MSPDI comprime dias de trabalho igual em blocos, e o split exato do mês
depende do motor diário do MS Project). Os **totais fecham exatos**.

Como decidido, o sistema **não esconde** isso:
- Na **prévia da importação**, mostra que os totais conferem (✓) e avisa
  do possível desvio de distribuição mensal vs MS Project.
- Cada importação grava `metodo_distribuicao` (transparência de como foi
  reconstruído).
- Nas **curvas e relatórios**, um selo informa que os valores são
  reconstruídos do cronograma e podem ter pequeno desvio.

## Edição manual (cravar o número do MS Project)

Tabela `curva_ajustes` (obra × série × granularidade × período): o usuário
sobrepõe o valor calculado com o valor exato que vê no MS Project. O valor
calculado **não é destruído** — fica para o "antes/depois" e auditoria.

- A curva/relatório exibe `valor_ajustado` quando existe, senão o calculado.
- Células ajustadas recebem marcador visual + tooltip com o valor original
  e quem ajustou.
- Guardamos `valor_calculado_no_ajuste`; numa reimportação, se a base mudar,
  o ajuste é sinalizado como possivelmente obsoleto para revisão.

## Modelo (resumo)

- `atividades` (+): origem, external_uid, datas (atual/baseline/real),
  baseline_horas, work_horas, real_horas, textos (json), is_marco,
  fora_do_cronograma; pacote_trabalho_id nullable.
- `pacotes_trabalho` (+): external_uid.
- `cronograma_importacoes`: snapshot + data_status + **metodo_distribuicao**.
- `avanco_periodos`: HH por atividade/período/série/granularidade (calculado).
- `curva_ajustes`: overrides manuais por período (obra-nível).

## Curva (cálculo, etapa seguinte)

Curva de uma etapa = soma acumulada de `avanco_periodos` no escopo,
por série, **sobreposta** pelos `curva_ajustes` quando existirem,
dividida pelo total da etapa (% por período). Escopo selecionável
(obra, pacote, ou filtro por texto personalizado).

## Fiação

- Bind `ImportadorCronograma => MsProjectImporter` no `AppServiceProvider`.
- Worker de fila ativo (`php artisan queue:work`).
- Produção: trocar o `simplexml` do importador por `XMLReader` (streaming)
  — cronograma real tem dezenas de MB.

## Prompt para o Claude Code (Onda 7)

```
Onda 7 — Importação de cronograma do MS Project + curvas de avanço.
Forma-alvo: @referencia/dcf-radar/app/Imports, @referencia/dcf-radar/app/Models
e @docs/ONDA-7-IMPORTACAO-CRONOGRAMA.md.

1. Migrations (após as tabelas de domínio): colunas de avanço/importação em
   atividades; external_uid em pacotes_trabalho; cronograma_importacoes (com
   metodo_distribuicao); avanco_periodos; curva_ajustes. Conferir nomes reais.
2. Enums OrigemAtividade, GranularidadePeriodo, SerieAvanco; ajustar Atividade
   (casts textos=json/datas; relação avancoPeriodos). Models AvancoPeriodo,
   CronogramaImportacao, CurvaAjuste.
3. MsProjectImporter com as TRÊS regras validadas: só recurso de Trabalho
   (Type=1); Value como duração; distribuição pelo ponto médio do bloco.
   Em produção, usar XMLReader (streaming) no lugar do simplexml.
4. ImportarCronogramaJob na fila (TenantContext::actingAs).
5. Controller + tela de upload com PRÉVIA: mostra criadas/atualizadas/
   arquivadas E o aviso de desvio + conferência de totais (✓). Removidas com
   restrições são destacadas, nunca apagadas.
6. Curvas: serviço que agrega avanco_periodos por escopo e sobrepõe
   curva_ajustes; UI permite editar o valor de um período (cria/atualiza
   curva_ajuste, com marcador de "ajustado" e tooltip do valor original).
7. Selo de transparência nas telas de curva.
8. Bind no AppServiceProvider.
9. Testes: importar o cronograma real de exemplo; conferir totais exatos
   (770.640 / 107.502 / 770.640 no caso de referência); editar um período e
   conferir que a curva passa a usar o valor ajustado; reimportar e conferir
   sinalização de ajuste obsoleto. Rodar php artisan test.
```
