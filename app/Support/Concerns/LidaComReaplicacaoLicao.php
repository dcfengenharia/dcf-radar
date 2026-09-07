<?php

namespace App\Support\Concerns;

use App\Actions\LicoesAprendidas\AvaliarReaplicacaoLicao;
use App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao;
use App\Enums\ResultadoAvaliacaoReaplicacao;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Exceptions\AvaliacaoReaplicacaoNaoAutorizadaException;
use App\Exceptions\ReaplicacaoLicaoInvalidaException;
use App\Exceptions\ReaplicacaoLicaoJaRegistradaException;
use App\Exceptions\ReaplicacaoLicaoNaoAutorizadaException;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaReaplicacao;
use App\Models\Work;
use Illuminate\Support\Facades\Auth;

/**
 * Ciclo 23, Etapa 23.5.B — comportamento de "registrar reaplicação" +
 * "avaliar resultado" compartilhado entre os 3 pontos de UI (Lookahead,
 * Estoque, Biblioteca Corporativa) — mesmo espírito de
 * `App\Support\Concerns\ExecutaComTransacaoSegura`, já usado nesses
 * mesmos 3 componentes: um trait pequeno e focado, nunca uma
 * duplicação de ~80 linhas em cada arquivo.
 *
 * Ciclo 23.5.B.CORREÇÃO (Seção 1) — autorização é checada EM DOBRO, de
 * propósito, nunca como redundância inútil: aqui (`Auth::user()->can()`)
 * é só UX — evita o round-trip até a Action pra mostrar a mensagem de
 * erro mais cedo, com o usuário da SESSÃO atual (`Auth::user()`, correto
 * neste contexto — é sempre um componente Livewire de uma requisição web
 * autenticada, nunca um Job/CLI). A defesa REAL, que faz o write-path
 * seguro por construção mesmo se este trait for contornado/uma chamada
 * direta às Actions esquecer de checar Policy, vive DENTRO de
 * `App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao`/
 * `AvaliarReaplicacaoLicao`, que revalidam a MESMA Policy com o ator
 * EXPLÍCITO recebido — por isso os `catch` abaixo cobrem as exceções de
 * autorização das Actions também, nunca só as de domínio.
 *
 * Cada método de "registrar"/"avaliar" retorna `bool` (sucesso/falha) —
 * o COMPONENTE consumidor decide o que invalidar (`unset($this->...)`)
 * depois, já que os nomes dos `#[Computed]` batch variam por tela
 * (`modalLicoesContextuais`, `licoesContextuaisPorMaterial`, `licoes`).
 */
trait LidaComReaplicacaoLicao
{
    public ?string $avaliarReaplicacaoId = null;
    public string $avaliarResultado = '';
    public string $avaliarObservacao = '';
    public ?string $reaplicacaoErro = null;

    /**
     * @param  array<int, array{tipo: TipoEntidadeVinculoLicao, id: string}>  $contextos
     */
    public function registrarReaplicacaoLicao(string $licaoId, string $obraId, ?string $observacaoInicial = null, array $contextos = []): bool
    {
        $licao = LicaoAprendida::find($licaoId);
        $obra = Work::find($obraId);

        if (! $licao || ! $obra) {
            $this->reaplicacaoErro = 'Lição ou obra não encontrada.';
            return false;
        }

        if (! Auth::user()->can('registrar', [LicaoAprendidaReaplicacao::class, $obra])) {
            $this->reaplicacaoErro = 'Você não tem permissão para registrar reaplicação nesta obra.';
            return false;
        }

        try {
            app(RegistrarReaplicacaoLicao::class)->execute($licao, $obra, Auth::user(), $observacaoInicial, $contextos);
            $this->reaplicacaoErro = null;
            $this->dispatch('show-toast', message: 'Reaplicação registrada nesta obra.', type: 'success');

            return true;
        } catch (ReaplicacaoLicaoInvalidaException|ReaplicacaoLicaoJaRegistradaException|ReaplicacaoLicaoNaoAutorizadaException $e) {
            $this->reaplicacaoErro = $e->getMessage();

            return false;
        }
    }

    public function abrirAvaliarReaplicacao(string $reaplicacaoId): void
    {
        $this->avaliarReaplicacaoId = $reaplicacaoId;
        $this->avaliarResultado = '';
        $this->avaliarObservacao = '';
        $this->reaplicacaoErro = null;
    }

    public function fecharAvaliarReaplicacao(): void
    {
        $this->avaliarReaplicacaoId = null;
        $this->avaliarResultado = '';
        $this->avaliarObservacao = '';
    }

    public function confirmarAvaliarReaplicacao(): bool
    {
        $reaplicacao = $this->avaliarReaplicacaoId ? LicaoAprendidaReaplicacao::find($this->avaliarReaplicacaoId) : null;

        if (! $reaplicacao) {
            $this->reaplicacaoErro = 'Reaplicação não encontrada.';
            return false;
        }

        if (! Auth::user()->can('avaliar', $reaplicacao)) {
            $this->reaplicacaoErro = 'Você não tem permissão para avaliar esta reaplicação.';
            return false;
        }

        $resultado = ResultadoAvaliacaoReaplicacao::tryFrom($this->avaliarResultado);
        if (! $resultado) {
            $this->reaplicacaoErro = 'Selecione um resultado válido.';
            return false;
        }

        try {
            app(AvaliarReaplicacaoLicao::class)->execute(
                $reaplicacao,
                $resultado,
                Auth::user(),
                $this->avaliarObservacao !== '' ? $this->avaliarObservacao : null,
            );
        } catch (AvaliacaoReaplicacaoNaoAutorizadaException $e) {
            $this->reaplicacaoErro = $e->getMessage();

            return false;
        }

        $this->fecharAvaliarReaplicacao();
        $this->dispatch('show-toast', message: 'Avaliação registrada.', type: 'success');

        return true;
    }
}
