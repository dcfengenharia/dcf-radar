<x-form-section submit="updateProfileInformation">
  <x-slot name="title">
    {{ __('Profile Information') }}
  </x-slot>

  <x-slot name="description">
    {{ __('Update your account\'s profile information and email address.') }}
  </x-slot>

  <x-slot name="form">

    <x-action-message on="saved">
      {{ __('Saved.') }}
    </x-action-message>

    <!-- Profile Photo -->
    @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
      <div class="mb-3" x-data="{photoName: null, photoPreview: null}">
        <!-- Profile Photo File Input -->
        <input type="file" hidden wire:model.live="photo" x-ref="photo"
          x-on:change=" photoName = $refs.photo.files[0].name; const reader = new FileReader(); reader.onload = (e) => { photoPreview = e.target.result;}; reader.readAsDataURL($refs.photo.files[0]);" />

        <!-- Current Profile Photo -->
        <div class="mt-2" x-show="! photoPreview">
          <img src="{{ $this->user->profile_photo_url }}" class="rounded-circle" height="80px" width="80px">
        </div>

        <!-- New Profile Photo Preview -->
        <div class="mt-2" x-show="photoPreview">
          <img x-bind:src="photoPreview" class="rounded-circle" width="80px" height="80px">
        </div>

        <x-secondary-button class="mt-2 me-2" type="button" x-on:click.prevent="$refs.photo.click()">
          {{ __('Select A New Photo') }}
        </x-secondary-button>

        @if ($this->user->profile_photo_path)
          <button type="button" class="btn btn-danger text-uppercase mt-2" wire:click="deleteProfilePhoto">
            {{ __('Remove Photo') }}
          </button>
        @endif

        <x-input-error for="photo" class="mt-2" />
      </div>
    @endif

    <!-- Nome -->
    <div class="row">
      <div class="col-md-6 mb-3">
        <x-label class="form-label" for="first_name" value="Nome" />
        <x-input id="first_name" type="text" class="{{ $errors->has('first_name') ? 'is-invalid' : '' }}"
          wire:model="state.first_name" autocomplete="given-name" />
        <x-input-error for="first_name" />
      </div>
      <div class="col-md-6 mb-3">
        <x-label class="form-label" for="last_name" value="Sobrenome" />
        <x-input id="last_name" type="text" class="{{ $errors->has('last_name') ? 'is-invalid' : '' }}"
          wire:model="state.last_name" autocomplete="family-name" />
        <x-input-error for="last_name" />
      </div>
    </div>

    <!-- Email -->
    <div class="mb-3">
      <x-label class="form-label" for="email" value="E-mail" />
      <x-input id="email" type="email" class="{{ $errors->has('email') ? 'is-invalid' : '' }}"
        wire:model="state.email" />
      <x-input-error for="email" />
    </div>

    <!-- Cargo e Telefone -->
    <div class="row">
      <div class="col-md-6 mb-3">
        <x-label class="form-label" for="cargo" value="Cargo na empresa" />
        <x-input id="cargo" type="text" class="{{ $errors->has('cargo') ? 'is-invalid' : '' }}"
          wire:model="state.cargo" placeholder="Ex: Engenheiro de Planejamento" />
        <x-input-error for="cargo" />
      </div>
      <div class="col-md-6 mb-3">
        <x-label class="form-label" for="telefone" value="Telefone" />
        <x-input id="telefone" type="text" class="{{ $errors->has('telefone') ? 'is-invalid' : '' }}"
          wire:model="state.telefone" placeholder="Ex: (11) 91234-5678" />
        <x-input-error for="telefone" />
      </div>
    </div>
  </x-slot>

  <x-slot name="actions">
    <div class="d-flex align-items-baseline">
      <x-button>
        {{ __('Save') }}
      </x-button>
    </div>
  </x-slot>
</x-form-section>
