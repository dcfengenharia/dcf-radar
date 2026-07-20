<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoverAdminPlataformaCommand extends Command
{
    protected $signature = 'admin:promover {email : E-mail do usuário que vai virar dono da plataforma}';

    protected $description = 'Marca um usuário existente como is_platform_admin=true, dando acesso à área de administração da plataforma.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("Nenhum usuário encontrado com o e-mail {$email}.");
            return self::FAILURE;
        }

        if ($user->is_platform_admin) {
            $this->info("{$email} já é administrador da plataforma.");
            return self::SUCCESS;
        }

        $user->update(['is_platform_admin' => true]);
        $this->info("{$email} agora é administrador da plataforma.");

        return self::SUCCESS;
    }
}
