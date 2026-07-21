<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SeedOwnerCommand extends Command
{
    protected $signature = 'app:seed-owner
                            {--email=contato@dcf.eng.br : E-mail do usuário dono}
                            {--password=password : Senha a definir}
                            {--first-name=Danúzio : Primeiro nome}
                            {--last-name=Ferreira : Sobrenome}
                            {--tenant= : ID do tenant (deixe vazio para usar o primeiro existente, ou criar a conta operadora se nenhum existir)}
                            {--empresa=DCF.eng : Nome da conta operadora, usado só quando um tenant novo precisa ser criado}';

    protected $description = 'Cria ou redefine o usuário administrador da plataforma. Sem --tenant e sem nenhum tenant no banco, cria a conta operadora (eh_conta_operadora=true, sem trial fake) — bootstrap sem precisar passar pelo /register público.';

    public function handle(): int
    {
        $email     = $this->option('email');
        $password  = $this->option('password');
        $firstName = $this->option('first-name');
        $lastName  = $this->option('last-name');
        $tenantId  = $this->option('tenant');
        $empresa   = $this->option('empresa');

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

        // Cria ou atualiza o usuário. `email_verified_at` não está em
        // $fillable (protegido de mass-assignment em código de request) —
        // updateOrCreate() sozinho descartava esse campo em silêncio,
        // deixando o usuário sem e-mail verificado e travado na tela de
        // verificação no primeiro login. forceFill() aqui é seguro: é um
        // comando de CLI restrito (dev/staging), nunca input de request.
        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => $email],
            [
                'first_name'         => $firstName,
                'last_name'          => $lastName,
                'tenant_id'          => $tenant->id,
                'password'           => Hash::make($password),
                'is_platform_admin'  => true,
                'status'             => 'active',
            ]
        );
        $user->forceFill(['email_verified_at' => now()])->save();

        $action = $user->wasRecentlyCreated ? 'criado' : 'atualizado';

        $this->info("Usuário {$action} com sucesso!");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['E-mail',    $user->email],
                ['Senha',     $password],
                ['Nome',      $user->first_name . ' ' . $user->last_name],
                ['Tenant',    $tenant->name ?? $tenant->id],
                ['Conta operadora', $tenant->eh_conta_operadora ? 'Sim' : 'Não'],
                ['Admin',     $user->is_platform_admin ? 'Sim' : 'Não'],
                ['Verificado','Sim'],
            ]
        );

        return self::SUCCESS;
    }
}
