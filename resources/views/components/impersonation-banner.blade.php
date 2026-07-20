@php
    use App\Support\ImpersonationContext;

    $tenantImpersonado = ImpersonationContext::current();
@endphp

@if ($tenantImpersonado)
<div class="alert alert-danger d-flex align-items-center justify-content-between gap-2 mb-0 rounded-0 py-2" role="alert">
    <span>
        <i class='bx bx-user-voice'></i>
        Você está navegando como <strong>{{ $tenantImpersonado->name }}</strong> (modo administrador).
    </span>
    <form method="POST" action="{{ route('admin.impersonar.parar') }}" class="mb-0">
        @csrf
        <button type="submit" class="btn btn-sm btn-light">Voltar ao modo administrador</button>
    </form>
</div>
@endif
