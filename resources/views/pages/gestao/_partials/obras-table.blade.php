@if ($works->count() > 0)
    <div class="card table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="card-header bg-dark">
                <tr>
                    <th class="text-white">Obra</th>
                    <th class="text-white">Cliente</th>
                    <th class="text-white">Localização</th>
                    <th class="text-white">Período</th>
                    <th class="text-white text-center">Avanço</th>
                    <th class="text-white text-center">Equipe</th>
                    <th class="text-white text-center">Status</th>
                    <th class="text-white text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="card-body">
                @foreach ($works as $work)
                    @php $avanco = (float) ($work->avanco_realizado ?? 0); @endphp
                    <tr>
                        <td>
                            <span class="fw-semibold">{{ $work->name }}</span>
                            @if ($work->budget_total)
                                <br><small class="text-muted">R$ {{ number_format($work->budget_total, 2, ',', '.') }}</small>
                            @endif
                        </td>
                        <td>{{ $work->client?->name ?? '—' }}</td>
                        <td><span class="text-muted">{{ $work->location ?? '—' }}</span></td>
                        <td>
                            @if ($work->start_date_baseline || $work->end_date_baseline)
                                <small>
                                    {{ $work->start_date_baseline?->format('d/m/Y') ?? '?' }}
                                    → {{ $work->end_date_baseline?->format('d/m/Y') ?? '?' }}
                                </small>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td class="text-center" style="min-width: 110px;">
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height: 6px;">
                                    <div class="progress-bar bg-success"
                                         role="progressbar"
                                         style="width: {{ min($avanco, 100) }}%"
                                         aria-valuenow="{{ $avanco }}"
                                         aria-valuemin="0"
                                         aria-valuemax="100">
                                    </div>
                                </div>
                                <small class="text-muted fw-semibold" style="min-width: 34px;">{{ number_format($avanco, 1, ',', '') }}%</small>
                            </div>
                        </td>
                        <td class="text-center" style="min-width: 100px;">
                            <div class="d-flex justify-content-center align-items-center gap-2">
                                <ul class="avatar-group d-flex align-items-center mb-0">
                                    @foreach ($work->users->take(3) as $membro)
                                        <li class="avatar avatar-xs pull-up" data-bs-toggle="tooltip" title="{{ $membro->first_name }} {{ $membro->last_name }}">
                                            <img src="{{ $membro->profile_photo_url }}" alt="{{ $membro->first_name }} {{ $membro->last_name }}" class="rounded-circle">
                                        </li>
                                    @endforeach
                                    @if ($work->users_count > 3)
                                        <li class="avatar avatar-xs pull-up">
                                            <span class="avatar-initial rounded-circle bg-label-secondary">+{{ $work->users_count - 3 }}</span>
                                        </li>
                                    @endif
                                </ul>
                                @if ($work->users_count === 0)
                                    <small class="text-muted">—</small>
                                @endif
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge {{ $work->status_badge }}">
                                {{ match($work->status) {
                                    'planejamento' => 'Planejamento',
                                    'em_andamento' => 'Em Andamento',
                                    'paralisada'   => 'Paralisada',
                                    'concluida'    => 'Concluída',
                                    default        => $work->status,
                                } }}
                            </span>
                        </td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <a href="{{ route('gestao.obra.show', $work) }}"
                                   class="btn btn-sm btn-outline-secondary"
                                   title="Ver detalhes da obra">
                                    <i class="bx bx-detail"></i>
                                </a>
                                @if (auth()->user()->temAcessoAObra($work))
                                    <a href="{{ route('radar.entrar', $work) }}"
                                       class="btn btn-sm btn-primary"
                                       title="Acessar o Radar desta obra">
                                        <i class="bx bx-shield-alt-2 me-1"></i>Entrar
                                    </a>
                                @else
                                    <span class="text-muted small align-self-center">Sem acesso ao Radar</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-center mt-3">
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
