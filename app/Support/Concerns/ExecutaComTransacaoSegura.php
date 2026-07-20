<?php

namespace App\Support\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

trait ExecutaComTransacaoSegura
{
    /**
     * Marcado true pelo transacaoSegura() quando a última chamada
     * falhou — o chamador DEVE checar isso (via transacaoSeguraFalhou())
     * e interromper o fluxo de sucesso (return cedo), já que o closure
     * pode não retornar nada mesmo tendo funcionado (métodos void), então
     * o valor de retorno sozinho não basta pra saber se deu certo.
     */
    private bool $ultimaTransacaoSeguraFalhou = false;

    /**
     * Roda o closure dentro de uma DB::transaction. Se algo inesperado
     * falhar (não uma autorização ou validação — essas têm tratamento
     * próprio e continuam subindo normalmente), desfaz a transação,
     * registra o erro e avisa o usuário com um toastr em vez de
     * quebrar a tela.
     */
    protected function transacaoSegura(\Closure $callback, string $mensagemErro = 'Não foi possível concluir a ação. Tente novamente em instantes.'): mixed
    {
        $this->ultimaTransacaoSeguraFalhou = false;

        try {
            return DB::transaction($callback);
        } catch (AuthorizationException|ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->ultimaTransacaoSeguraFalhou = true;
            $this->dispatch('show-toast', message: $mensagemErro, type: 'error');

            return null;
        }
    }

    protected function transacaoSeguraFalhou(): bool
    {
        return $this->ultimaTransacaoSeguraFalhou;
    }
}
