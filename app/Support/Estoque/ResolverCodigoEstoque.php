<?php

namespace App\Support\Estoque;

use App\Exceptions\CodigoEstoqueInvalidoException;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\UnidadeEstoque;

/**
 * Ciclo 20, Etapa 20.8 — serviço CENTRAL de resolução de código
 * escaneado (QR/manual/scanner teclado) — nunca duplicar este parsing em
 * cada tela (Seção 14 do pedido).
 *
 * **Princípio central**: o código só IDENTIFICA a entidade — nunca
 * carrega saldo/quantidade/Local atual/regra de negócio. Resolver aqui é
 * só "qual Material/UnidadeEstoque/LocalEstoque é este?" — toda consulta
 * de saldo/situação continua vindo do ledger
 * (`App\Support\Estoque\SaldoEstoque` etc.), nunca do próprio código.
 *
 * **Decisão do usuário (STOP-and-ask, Seção 4)**: o código reaproveita o
 * ULID já existente como PK de cada entidade — formato `MAT:{ulid}` /
 * `UNI:{ulid}` / `LOC:{ulid}` — ZERO migration, ZERO coluna nova. Nunca
 * exposto fora de área autenticada (Seção 26); tenant/obra sempre
 * revalidados aqui, nunca confiado só à UI (Seção 15).
 *
 * **Cross-tenant — decisão de segurança deliberada**: `Material`/
 * `UnidadeEstoque`/`LocalEstoque` usam `BelongsToTenant` — um ULID de
 * OUTRO tenant escaneado aqui simplesmente não é encontrado pelo
 * `Model::find()` (o global scope já filtra). Isso produz a MESMA
 * mensagem de "código desconhecido" que um ULID que nunca existiu —
 * NUNCA diferenciamos as duas nesta camada, porque diferenciar
 * revelaria "este código existe, só não é seu" pra um usuário de outro
 * tenant, um vazamento de informação que o isolamento de tenant deste
 * projeto nunca permite em nenhum outro ponto do sistema.
 *
 * **Cross-obra**: só `LocalEstoque`/`UnidadeEstoque` têm obra (via
 * `local_estoque_id.obra_id`) — `Material` é catálogo do TENANT inteiro,
 * reutilizável entre obras (nunca obra-scoped), então nunca é rejeitado
 * por obra aqui.
 *
 * **Material/Local inativo — nunca bloqueia a resolução** (Seção 16:
 * "Resolver != autorizar operação"). O resultado carrega `ativo` pra a
 * UI avisar, mas quem de fato BLOQUEIA uma operação sobre entidade
 * inativa continua sendo, exclusivamente, a Action de domínio já
 * existente (ex.: `RegistrarEntradaEstoque::garantirMaterialAtivo()`) —
 * nunca duplicado aqui.
 */
class ResolverCodigoEstoque
{
    public static function resolver(string $codigoEscaneado, ?string $obraIdEsperada = null): ResultadoResolucaoCodigoEstoque
    {
        $codigo = trim($codigoEscaneado);
        if ($codigo === '') {
            throw new CodigoEstoqueInvalidoException('Código vazio.');
        }

        if (! str_contains($codigo, ':')) {
            throw new CodigoEstoqueInvalidoException('Código inválido — formato não reconhecido.');
        }

        [$prefixo, $ulid] = explode(':', $codigo, 2);
        $prefixo = strtoupper(trim($prefixo));
        $ulid = trim($ulid);

        if ($ulid === '') {
            throw new CodigoEstoqueInvalidoException('Código inválido — formato não reconhecido.');
        }

        $entidade = match ($prefixo) {
            'MAT' => Material::find($ulid),
            'UNI' => UnidadeEstoque::find($ulid),
            'LOC' => LocalEstoque::find($ulid),
            default => throw new CodigoEstoqueInvalidoException("Código inválido — tipo '{$prefixo}' não reconhecido."),
        };

        if (! $entidade) {
            // Cobre tanto "nunca existiu" quanto "existe, mas é de outro
            // tenant" (BelongsToTenant já filtrou) — nunca diferenciado
            // pro chamador, por design (ver docblock da classe).
            throw new CodigoEstoqueInvalidoException('Código desconhecido — nenhuma entidade corresponde a ele.');
        }

        $tipo = match ($prefixo) {
            'MAT' => 'material',
            'UNI' => 'unidade',
            'LOC' => 'local',
        };

        if ($obraIdEsperada !== null) {
            $obraDaEntidade = match ($tipo) {
                'unidade' => $entidade->localEstoque?->obra_id,
                'local' => $entidade->obra_id,
                'material' => null, // Material nunca é obra-scoped.
            };

            if ($obraDaEntidade !== null && $obraDaEntidade !== $obraIdEsperada) {
                throw new CodigoEstoqueInvalidoException('Este código pertence a outra obra.');
            }
        }

        $ativo = match ($tipo) {
            'material', 'local' => (bool) $entidade->ativo,
            'unidade' => null, // UnidadeEstoque nunca teve conceito de ativo/inativo.
        };

        return new ResultadoResolucaoCodigoEstoque($tipo, $entidade, $ativo);
    }
}
