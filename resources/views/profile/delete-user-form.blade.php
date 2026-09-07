<x-action-section>
  <x-slot name="title">
    Desativar minha conta
  </x-slot>

  <x-slot name="description">
    Encerra imediatamente o seu acesso à plataforma.
  </x-slot>

  <x-slot name="content">
    <div>
      Ao desativar, você é desconectado na hora e não consegue mais entrar
      com esta conta. Isto <strong>não apaga</strong> seu histórico —
      atividades, restrições, comentários e demais registros em que você
      aparece como autor ou responsável continuam existindo normalmente,
      preservando a rastreabilidade do trabalho já feito na obra. Se você
      precisar de uma exclusão definitiva dos seus dados pessoais, entre em
      contato com o administrador da sua empresa ou com o suporte do
      DCF.eng.
    </div>

    <div class="mt-3">
      <x-danger-button wire:click="confirmUserDeletion" wire:loading.attr="disabled">
        Desativar minha conta
      </x-danger-button>
    </div>

    <!-- Delete User Confirmation Modal -->
    <x-dialog-modal wire:model.live="confirmingUserDeletion">
      <x-slot name="title">
        Desativar minha conta
      </x-slot>

      <x-slot name="content">
        Tem certeza que quer desativar sua conta? Você será desconectado
        imediatamente e não poderá mais entrar com este e-mail até que um
        administrador reative o acesso. Seu histórico de atividade não é
        apagado. Digite sua senha para confirmar.

        <div class="mt-2" x-data="{}"
          x-on:confirming-delete-user.window="setTimeout(() => $refs.password.focus(), 250)">
          <x-input type="password" class="{{ $errors->has('password') ? 'is-invalid' : '' }}"
            placeholder="{{ __('Password') }}" x-ref="password" wire:model="password"
            wire:keydown.enter="deleteUser" />

          <x-input-error for="password" />
        </div>
      </x-slot>

      <x-slot name="footer">
        <x-secondary-button wire:click="$toggle('confirmingUserDeletion')" wire:loading.attr="disabled">
          {{ __('Cancel') }}
        </x-secondary-button>

        <x-danger-button class="ms-1" wire:click="deleteUser" wire:loading.attr="disabled">
          Desativar minha conta
        </x-danger-button>
      </x-slot>
    </x-dialog-modal>
  </x-slot>

</x-action-section>
