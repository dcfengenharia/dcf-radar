<?php

namespace App\Livewire\Profile;

use App\Actions\ExportUserData;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ExportUserDataForm extends Component
{
    public function exportar()
    {
        $usuario = Auth::user();
        $dados = app(ExportUserData::class)->gerar($usuario);

        $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $nomeArquivo = 'meus-dados-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(fn () => print($json), $nomeArquivo, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function render()
    {
        return view('livewire.profile.export-user-data-form');
    }
}
