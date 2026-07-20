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
                            {--tenant= : ID do tenant (deixe vazio para usar o primeiro existente)}';

    protected $description = 'Cria ou redefine o usuário dono da plataforma, vinculado a um tenant existente. Uso em dev/staging.';

    public function handle(): int
    {
        $email     = $this->option('email');
        $password  = $this->option('password');
        $firstName = $this->option('first-name');
        $lastName  = $this->option('last-name');
        $tenantId  = $this->option('tenant');

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
                $this->error('Nenhum tenant encontrado no banco. Registre-se uma vez pelo UI para criar o tenant inicial.');
                return self::FAILURE;
            }
        }

        // Cria ou atualiza o usuário
        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => $email],
            [
                'first_name'         => $firstName,
                'last_name'          => $lastName,
                'tenant_id'          => $tenant->id,
                'password'           => Hash::make($password),
                'email_verified_at'  => now(),
                'is_platform_admin'  => true,
                'status'             => 'active',
            ]
        );

        $action = $user->wasRecentlyCreated ? 'criado' : 'atualizado';

        $this->info("Usuário {$action} com sucesso!");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['E-mail',    $user->email],
                ['Senha',     $password],
                ['Nome',      $user->first_name . ' ' . $user->last_name],
                ['Tenant',    $tenant->name ?? $tenant->id],
                ['Admin',     $user->is_platform_admin ? 'Sim' : 'Não'],
                ['Verificado','Sim'],
            ]
        );

        return self::SUCCESS;
    }
}
