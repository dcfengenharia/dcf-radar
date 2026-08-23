<?php

namespace App\Console\Commands;

use App\Actions\Engenharia\AnexarRevisaoDocumento;
use App\Models\DocumentoEngenhariaRevisao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo 18, Etapa 18.2 (+ 18.2.HARDENING) — migração FÍSICA (copia, nunca
 * move) de arquivos de revisão do disco público legado ('public') pro
 * disco privado ('local', App\Actions\Engenharia\AnexarRevisaoDocumento::DISCO).
 *
 * Decisões deliberadas (não corrigir/ampliar sem aprovação):
 * - COPIA, nunca apaga a origem pública — decisão explícita do usuário,
 *   "prefiro copiar + atualizar banco; só apagar legado depois de toda
 *   validação, numa etapa explícita". Limpeza do disco público fica
 *   registrada como dívida futura (ver relatório da Etapa 18.2).
 * - Sem coluna nova no banco pra marcar "migrado": `anexo_path` é a MESMA
 *   string de path nos dois discos (só o disco muda) — idempotência vem
 *   de comparar CONTEÚDO (hash), nunca de um flag persistido.
 * - Nunca atualiza `documento_engenharia_revisoes` — não há nada pra
 *   atualizar (mesma decisão acima). "Validar destino antes do banco" da
 *   instrução original não se aplica aqui porque não existe update de
 *   banco nesta operação — é puramente uma cópia de arquivo.
 * - Roda SEM TenantContext::actingAs() — comando de CLI/plataforma,
 *   TenantScope::apply() não filtra sem contexto ativo (mesmo
 *   comportamento já usado por outros commands agendados do projeto),
 *   então itera revisões de TODOS os tenants numa única passada.
 * - chunkById (não offset/limit) — sem N+1 grosseiro em volume alto,
 *   mesmo padrão já usado noutros pontos do projeto pra varredura em
 *   lote.
 *
 * Etapa 18.2.HARDENING (achado B2 da auditoria adversarial) — "já
 * migrado" deixou de significar apenas "o path existe no privado".
 * Quando origem pública E destino privado existem SIMULTANEAMENTE, o
 * conteúdo dos dois é comparado por hash (streaming, nunca carrega o
 * arquivo inteiro em memória) antes de decidir. 5 casos possíveis, ver
 * migrarUma(). Conflito (conteúdos diferentes) NUNCA sobrescreve
 * silenciosamente — fica registrado como um resultado próprio,
 * preservando os dois arquivos intactos pra diagnóstico humano.
 */
class MigrarRevisoesEngenhariaStoragePrivado extends Command
{
    protected $signature = 'engenharia:migrar-revisoes-storage-privado {--chunk=200}';

    protected $description = 'Copia arquivos de revisões de Documento de Engenharia do disco público legado pro disco privado (Ciclo 18, Etapa 18.2). Idempotente e verifica integridade por hash — nunca apaga a origem, nunca sobrescreve conflito.';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));

        $migrados = 0;
        $jaMigrados = 0;
        $ausentes = 0;
        $conflitos = 0;
        $falhas = 0;

        DocumentoEngenhariaRevisao::whereNotNull('anexo_path')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($revisoes) use (&$migrados, &$jaMigrados, &$ausentes, &$conflitos, &$falhas) {
                foreach ($revisoes as $revisao) {
                    match ($this->migrarUma($revisao)) {
                        'migrado' => $migrados++,
                        'ja_migrado' => $jaMigrados++,
                        'ausente' => $ausentes++,
                        'conflito' => $conflitos++,
                        'falha' => $falhas++,
                    };
                }
            });

        $this->info("Migrados nesta execução: {$migrados}");
        $this->info("Já estavam no privado (idempotente): {$jaMigrados}");

        if ($ausentes > 0) {
            $this->warn("Sem arquivo em nenhum disco (registro órfão): {$ausentes} — ver logs (report()) pra detalhes.");
        } else {
            $this->info('Sem arquivo em nenhum disco (registro órfão): 0');
        }

        if ($conflitos > 0) {
            $this->error("Conflitos: {$conflitos} — origem pública e destino privado com conteúdo DIFERENTE, nenhum dos dois foi alterado, ver logs (report()) pra diagnóstico manual.");
        } else {
            $this->info('Conflitos: 0');
        }

        if ($falhas > 0) {
            $this->error("Falhas: {$falhas} — ver logs (report()) pra detalhes.");
        } else {
            $this->info('Falhas: 0');
        }

        // "Ausente" continua exit 0 (contrato já aprovado na 18.2, não
        // alterado aqui) — só falha real de escrita ou conflito de
        // integridade tornam a execução não-zero, porque exigem
        // intervenção humana antes de reexecutar com confiança.
        return ($falhas > 0 || $conflitos > 0) ? self::FAILURE : self::SUCCESS;
    }

    private function migrarUma(DocumentoEngenhariaRevisao $revisao): string
    {
        $caminho = $revisao->anexo_path;
        $localExiste = Storage::disk(AnexarRevisaoDocumento::DISCO)->exists($caminho);
        $publicExiste = Storage::disk('public')->exists($caminho);

        // CASO 5 — nem local nem public têm o arquivo.
        if (! $localExiste && ! $publicExiste) {
            report(new \RuntimeException("MigrarRevisoesEngenhariaStoragePrivado: revisão {$revisao->id} sem arquivo em nenhum disco (path: {$caminho})."));

            return 'ausente';
        }

        // CASO 3 — só existe no privado (já migrado numa execução
        // anterior e a origem pública já não existe mais, ou o arquivo
        // sempre foi só privado). Válido, sem necessidade de comparação.
        if ($localExiste && ! $publicExiste) {
            return 'ja_migrado';
        }

        // CASO 1/2 — os dois existem: só considera "já migrado" se o
        // CONTEÚDO bater. Nunca decide só por existência do path.
        if ($localExiste && $publicExiste) {
            $identicos = $this->conteudosIdenticos($caminho);

            if ($identicos === true) {
                return 'ja_migrado'; // CASO 1
            }

            if ($identicos === false) {
                report(new \RuntimeException(
                    "MigrarRevisoesEngenhariaStoragePrivado: CONFLITO — revisão {$revisao->id} tem conteúdo DIFERENTE entre "
                    . "disco público e privado no mesmo path ({$caminho}). Nenhum dos dois foi alterado — requer diagnóstico manual."
                ));

                return 'conflito'; // CASO 2
            }

            // $identicos === null: não foi possível ler um dos dois pra
            // comparar (leitura falhou apesar de exists() ter dito que sim)
            // — trata como falha, nunca como sucesso otimista.
            report(new \RuntimeException("MigrarRevisoesEngenhariaStoragePrivado: revisão {$revisao->id} — falha ao ler stream pra comparação de integridade (path: {$caminho})."));

            return 'falha';
        }

        // CASO 4 — só existe no público: copia.
        $conteudo = Storage::disk('public')->get($caminho);
        $gravou = Storage::disk(AnexarRevisaoDocumento::DISCO)->put($caminho, $conteudo);

        if (! $gravou || ! Storage::disk(AnexarRevisaoDocumento::DISCO)->exists($caminho)) {
            report(new \RuntimeException("MigrarRevisoesEngenhariaStoragePrivado: falha ao gravar destino privado da revisão {$revisao->id} (path: {$caminho})."));

            return 'falha';
        }

        // Verificação pós-cópia obrigatória — put() ter retornado true não
        // basta; confirma que o destino realmente bate com a origem antes
        // de contar como "migrado".
        $identicos = $this->conteudosIdenticos($caminho);

        if ($identicos !== true) {
            report(new \RuntimeException("MigrarRevisoesEngenhariaStoragePrivado: falha de integridade PÓS-CÓPIA da revisão {$revisao->id} — destino não bate com a origem (path: {$caminho})."));

            return 'falha';
        }

        return 'migrado';
    }

    /**
     * Compara o conteúdo dos dois discos pra o mesmo path via hash
     * streaming (sha256, `hash_update_stream` — nunca carrega o arquivo
     * inteiro em memória). Atalho barato por tamanho antes do hash: dois
     * arquivos de tamanho diferente nunca são idênticos, sem precisar ler
     * nenhum byte. Retorna `null` (não `false`) quando não foi possível
     * LER algum dos dois pra comparar — nunca confundido com "diferentes".
     */
    private function conteudosIdenticos(string $caminho): ?bool
    {
        $tamanhoLocal = Storage::disk(AnexarRevisaoDocumento::DISCO)->size($caminho);
        $tamanhoPublic = Storage::disk('public')->size($caminho);

        if ($tamanhoLocal !== $tamanhoPublic) {
            return false;
        }

        $hashLocal = $this->hashDoArquivo(AnexarRevisaoDocumento::DISCO, $caminho);
        $hashPublic = $this->hashDoArquivo('public', $caminho);

        if ($hashLocal === null || $hashPublic === null) {
            return null;
        }

        return $hashLocal === $hashPublic;
    }

    private function hashDoArquivo(string $disco, string $caminho): ?string
    {
        $stream = Storage::disk($disco)->readStream($caminho);

        if ($stream === null) {
            return null;
        }

        try {
            $contexto = hash_init('sha256');
            hash_update_stream($contexto, $stream);

            return hash_final($contexto);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
