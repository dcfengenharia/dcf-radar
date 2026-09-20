<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SeedOwnerCommand extends Command
{
    protected $signature = 'app:seed-owner
                            {--email=contato@dcf.eng.br : E-mail do usuário dono}
                            {--password= : Senha a definir. Se omitido: usuário novo ganha senha aleatória forte (exibida só nesta execução); usuário já existente nunca tem a senha alterada}
                            {--reset-password : Confirma explicitamente que a senha de um admin JÁ EXISTENTE deve ser sobrescrita por --password (obrigatório junto de --password quando o e-mail já existe)}
                            {--first-name=Danúzio : Primeiro nome}
                            {--last-name=Ferreira : Sobrenome}
                            {--tenant= : ID do tenant (deixe vazio para usar o primeiro existente, ou criar a conta operadora se nenhum existir)}
                            {--empresa=DCF.eng : Nome da conta operadora, usado só quando um tenant novo precisa ser criado}';

    protected $description = 'Cria o usuário administrador da plataforma (ou atualiza seus dados cadastrais, nunca a senha, a menos que --reset-password seja passado explicitamente). Sem --tenant e sem nenhum tenant no banco, cria a conta operadora (eh_conta_operadora=true, sem trial fake) — bootstrap sem precisar passar pelo /register público.';

    private const TAMANHO_MINIMO_SENHA = 10;

    public function handle(): int
    {
        $email       = $this->option('email');
        $senhaInformada = $this->option('password');
        $resetSenha  = (bool) $this->option('reset-password');
        $firstName   = $this->option('first-name');
        $lastName    = $this->option('last-name');
        $tenantId    = $this->option('tenant');
        $empresa     = $this->option('empresa');

        if ($senhaInformada !== null && mb_strlen($senhaInformada) < self::TAMANHO_MINIMO_SENHA) {
            $this->error('A senha informada é curta demais (mínimo de '.self::TAMANHO_MINIMO_SENHA.' caracteres).');
            return self::FAILURE;
        }

        // Determina o tenant
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                $this->error("Tenant '{$tenantId}' não encontrado.");
                return self::FAILURE;
            }
        } else {
            $tenant = Tenant::first();
            if (! $tenant) {
                $tenant = Tenant::create([
                    'name' => $empresa,
                    'eh_conta_operadora' => true,
                ]);
                $this->info("Nenhum tenant encontrado — criada a conta operadora \"{$empresa}\".");
            }
        }

        $usuarioExistente = User::withoutGlobalScopes()->where('email', $email)->first();

        // Auditoria Pré-Produção A1, SEED-02 — três garantias, nesta ordem:
        // (1) nunca existe senha default — sem --password, um usuário NOVO
        // ganha uma senha aleatória forte gerada agora, nunca "password";
        // (2) nunca a senha de um admin já existente é sobrescrita em
        // silêncio — só muda com --password E --reset-password juntos,
        // ambos explícitos; (3) a senha só é exibida no console quando foi
        // GERADA por este comando (única chance de o operador vê-la) —
        // nunca ecoa de volta uma senha que o próprio operador já forneceu,
        // e nunca grava a senha em log.
        $senhaGeradaAgora = null;
        $vaiAlterarSenha = false;
        $senhaParaGravar = null;

        if (! $usuarioExistente) {
            if ($senhaInformada !== null) {
                $senhaParaGravar = $senhaInformada;
            } else {
                $senhaParaGravar = Str::password(24);
                $senhaGeradaAgora = $senhaParaGravar;
            }
            $vaiAlterarSenha = true;
        } elseif ($senhaInformada !== null) {
            if (! $resetSenha) {
                $this->error(
                    "O usuário '{$email}' já existe. Pra redefinir a senha dele, passe também --reset-password ".
                    '(confirmação explícita — nunca sobrescrevemos a senha de um admin existente em silêncio).'
                );
                return self::FAILURE;
            }
            $senhaParaGravar = $senhaInformada;
            $vaiAlterarSenha = true;
        }

        $dados = [
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'tenant_id'         => $tenant->id,
            'is_platform_admin' => true,
            'status'            => 'active',
        ];

        if ($vaiAlterarSenha) {
            $dados['password'] = Hash::make($senhaParaGravar);
        }

        // `email_verified_at` não está em $fillable (protegido de
        // mass-assignment em código de request) — updateOrCreate() sozinho
        // descartava esse campo em silêncio, deixando o usuário sem e-mail
        // verificado e travado na tela de verificação no primeiro login.
        // forceFill() aqui é seguro: é um comando de CLI restrito
        // (dev/staging/deploy), nunca input de request.
        $user = User::withoutGlobalScopes()->updateOrCreate(['email' => $email], $dados);
        $user->forceFill(['email_verified_at' => now()])->save();

        $action = $user->wasRecentlyCreated ? 'criado' : 'atualizado';

        $this->info("Usuário {$action} com sucesso!");
        $linhas = [
            ['E-mail', $user->email],
            ['Nome', $user->first_name.' '.$user->last_name],
            ['Tenant', $tenant->name ?? $tenant->id],
            ['Conta operadora', $tenant->eh_conta_operadora ? 'Sim' : 'Não'],
            ['Admin', $user->is_platform_admin ? 'Sim' : 'Não'],
            ['Verificado', 'Sim'],
            ['Senha alterada nesta execução', $vaiAlterarSenha ? 'Sim' : 'Não (preservada)'],
        ];
        $this->table(['Campo', 'Valor'], $linhas);

        if ($senhaGeradaAgora !== null) {
            $this->newLine();
            $this->warn('Senha gerada automaticamente (exibida só agora — anote em local seguro e troque no primeiro login):');
            $this->line($senhaGeradaAgora);
        }

        return self::SUCCESS;
    }
}
