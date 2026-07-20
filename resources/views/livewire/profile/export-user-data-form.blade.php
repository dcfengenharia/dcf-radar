<x-action-section>
  <x-slot name="title">
    Exportar meus dados
  </x-slot>

  <x-slot name="description">
    Baixe uma cópia dos seus dados pessoais e de tudo que você criou no sistema.
  </x-slot>

  <x-slot name="content">
    <div>
      O arquivo inclui seus dados de cadastro e todos os registros onde você aparece como autor ou responsável (atividades, restrições, comentários, relatórios e outros).
    </div>

    <div class="mt-3">
      <x-secondary-button wire:click="exportar" wire:loading.attr="disabled">
        Baixar meus dados
      </x-secondary-button>
    </div>
  </x-slot>
</x-action-section>
