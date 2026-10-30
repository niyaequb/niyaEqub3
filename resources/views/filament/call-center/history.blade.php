@php
    use App\Models\CallCenterLog;
@endphp

<div class="space-y-4">
    @if ($terminations->isNotEmpty())
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm dark:border-danger-700 dark:bg-danger-950">
            @foreach ($terminations as $t)
                <div>
                    <strong>{{ $t->reinstated_at ? 'Terminated, then reinstated' : 'Terminated' }}</strong>
                    on {{ $t->created_at?->format('M j, Y H:i') }}
                    by {{ $t->terminatedBy?->name ?? '—' }} — {{ $t->reasonLabel() }}
                    @if ($t->note) <div class="text-gray-600 dark:text-gray-300">{{ $t->note }}</div> @endif
                </div>
            @endforeach
        </div>
    @endif

    @forelse ($logs as $log)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <x-filament::badge :color="CallCenterLog::OUTCOME_COLORS[$log->outcome] ?? 'gray'">
                    {{ $log->outcomeLabel() }}
                </x-filament::badge>
                <span class="text-xs text-gray-500">
                    {{ $log->created_at?->format('M j, Y H:i') }} · {{ $log->caller?->name ?? 'Unknown' }}
                </span>
            </div>

            <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-gray-600 dark:text-gray-300">
                <div>Unpaid: <strong>{{ $log->days_unpaid_at_call }} days</strong></div>
                <div>Owed: <strong>{{ number_format((float) $log->arrears_at_call, 2) }} ETB</strong></div>
                @if ($log->promised_payment_date)
                    <div class="col-span-2">Promised to pay on: <strong>{{ $log->promised_payment_date->format('M j, Y') }}</strong></div>
                @endif
            </div>

            @if ($log->feedback)
                <p class="mt-2 whitespace-pre-line text-sm">{{ $log->feedback }}</p>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">No calls logged yet.</p>
    @endforelse
</div>
