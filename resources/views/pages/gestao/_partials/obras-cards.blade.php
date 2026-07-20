@if ($works->count() > 0)
    <div class="row g-4">
        @foreach ($works as $work)
            @php
                $avanco = (float) ($work->avanco_realizado ?? 0);
                $prazo = $work->end_date_baseline;
                $diasPrazo = $prazo ? now()->startOfDay()->diffInDays($prazo, true) : null;
                $prazoAtrasado = $prazo && $prazo->isPast();
            @endphp
            <div class="col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar avatar-sm bg-label-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                    <i class="bx bx-hard-hat"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0">{{ $work->name }}</h6>
                                    <small class="text-muted">Cliente: {{ $work->client?->name ?? '—' }}</small>
                                </div>
                            </div>
                            <span class="badge {{ $work->status_badge }}">
                                {{ match($work->status) {
                                    'planejamento' => 'Planejamento',
                                    'em_andamento' => 'Em Andamento',
                                    'paralisada'   => 'Paralisada',
                                    'concluida'    => 'Concluída',
                                    default        => $work->status,
                                } }}
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                @if ($work->budget_total)
                                    <div class="fw-semibold">R$ {{ number_format($work->budget_total, 2, ',', '.') }}</div>
                                    <small class="text-muted">Orçamento Total</small>
                                @else
                                    <small class="text-muted">Sem orçamento definido</small>
                                @endif
                            </div>
                            <div class="text-end">
                                @if ($work->start_date_baseline || $work->end_date_baseline)
                                    <small class="d-block">Início: {{ $work->start_date_baseline?->format('d/m/Y') ?? '?' }}</small>
                                    <small class="d-block">Término: {{ $work->end_date_baseline?->format('d/m/Y') ?? '?' }}</small>
                                @else
                                    <small class="text-muted">Sem período definido</small>
                                @endif
                            </div>
                        </div>

                        <p class="text-muted small mb-3">{{ $work->location ?? 'Localização não informada.' }}</p>

                        <hr class="my-3">

                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="fw-semibold">Avanço</small>
                            @if ($diasPrazo !== null)
                                <span class="badge {{ $prazoAtrasado ? 'bg-label-danger' : 'bg-label-success' }}">
                                    {{ (int) $diasPrazo }} {{ (int) $diasPrazo === 1 ? 'DIA' : 'DIAS' }} {{ $prazoAtrasado ? 'EM ATRASO' : 'RESTANTES' }}
                                </span>
                            @endif
                        </div>
                        <div class="progress mb-1" style="height: 6px;">
                            <div class="progress-bar bg-success"
                                 role="progressbar"
                                 style="width: {{ min($avanco, 100) }}%"
                                 aria-valuenow="{{ $avanco }}"
                                 aria-valuemin="0"
                                 aria-valuemax="100">
                            </div>
                        </div>
                        <div class="text-end mb-3">
                            <small class="text-muted">{{ number_format($avanco, 1, ',', '') }}% concluído</small>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <ul class="avatar-group d-flex align-items-center mb-0">
                                @foreach ($work->users->take(3) as $membro)
                                    <li class="avatar avatar-sm pull-up" data-bs-toggle="tooltip" title="{{ $membro->first_name }} {{ $membro->last_name }}">
                                        <img src="{{ $membro->profile_photo_url }}" alt="{{ $membro->first_name }} {{ $membro->last_name }}" class="rounded-circle">
                                    </li>
                                @endforeach
                                @if ($work->users_count > 3)
                                    <li class="avatar avatar-sm pull-up">
                                        <span class="avatar-initial rounded-circle bg-label-secondary">+{{ $work->users_count - 3 }}</span>
                                    </li>
                                @endif
                            </ul>
                            <small class="text-muted">
                                <i class="bx bx-group me-1"></i>{{ $work->users_count }} {{ $work->users_count === 1 ? 'membro' : 'membros' }}
                            </small>
                        </div>

                        <div class="d-flex gap-2">
                            <a href="{{ route('gestao.obra.show', $work) }}" class="btn btn-outline-secondary btn-sm flex-grow-1">
                                <i class="bx bx-detail me-1"></i>Ver Detalhes
                            </a>
                            @if (auth()->user()->temAcessoAObra($work))
                                <a href="{{ route('radar.entrar', $work) }}" class="btn btn-primary btn-sm flex-grow-1">
                                    <i class="bx bx-shield-alt-2 me-1"></i>Entrar
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex justify-content-center mt-4">
        {{ $works->links() }}
    </div>

@elseif ($search ?? false)
    <div class="text-center py-5">
        <div class="mb-3" style="font-size: 3rem;">🔍</div>
        <h5 class="fw-bold mb-2">Nenhuma obra encontrada</h5>
        <p class="text-muted">Não há resultados para "<strong>{{ $search }}</strong>".</p>
    </div>

@else
    <div class="text-center py-5">
        <div class="avatar avatar-xl mx-auto mb-4 bg-label-primary p-2 rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
            <i class="bx bx-hard-hat display-4 text-primary"></i>
        </div>
        <h5 class="fw-bold mb-2">{{ $emptyMessage }}</h5>
    </div>
@endif
