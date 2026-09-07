<?php

namespace App\Actions\LicoesAprendidas;

use App\Enums\StatusLicaoAprendida;
use App\Models\LicaoAprendida;
use App\Models\User;
use App\Models\Work;

/**
 * Ciclo 23, Etapa 23.1 — sempre nasce Rascunho. `$obra` é sempre
 * resolvida/validada pelo CHAMADOR (Policy + revalidação server-side de
 * `temPermissaoNaObra()`, nunca confiar em `obra_id` cru do request) —
 * esta Action nunca decide sozinha se o usuário pode criar na obra.
 */
class CriarLicaoAprendida
{
    public function execute(Work $obra, User $autor, array $dados): LicaoAprendida
    {
        return LicaoAprendida::create([
            'obra_origem_id' => $obra->id,
            // Explícito mesmo com default('rascunho') na migration — sem
            // isso, o objeto em memória retornado por create() fica com
            // `status` null até um fresh()/refresh() (Eloquent nunca relê
            // o default do banco sozinho), quebrando qualquer código que
            // use o objeto retornado sem recarregar.
            'status' => StatusLicaoAprendida::Rascunho->value,
            'disciplina_id' => $dados['disciplina_id'] ?? null,
            'titulo' => $dados['titulo'],
            'situacao_observada' => $dados['situacao_observada'],
            'causa' => $dados['causa'] ?? null,
            'impacto' => $dados['impacto'] ?? null,
            'acao_adotada' => $dados['acao_adotada'] ?? null,
            'resultado' => $dados['resultado'] ?? null,
            'recomendacao_futura' => $dados['recomendacao_futura'] ?? null,
            'tipo' => $dados['tipo'],
            'criticidade' => $dados['criticidade'],
            'area_funcional' => $dados['area_funcional'],
            'data_ocorrencia' => $dados['data_ocorrencia'] ?? null,
            'data_ocorrencia_fim' => $dados['data_ocorrencia_fim'] ?? null,
            'observacoes_internas' => $dados['observacoes_internas'] ?? null,
            'created_by_id' => $autor->id,
        ]);
    }
}
