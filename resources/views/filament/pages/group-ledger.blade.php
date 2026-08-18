<x-filament-panels::page>
    @php
        $group = $ledger['group'] ?? [];
        $members = $ledger['members'] ?? [];
        $money = fn ($v) => number_format((float) $v, 2) . ' ETB';
        $pct = fn ($v) => round(((float) $v) * 100) . '%';
        $badge = [
            'paid_up'   => ['label' => 'Paid up', 'class' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400'],
            'behind'    => ['label' => 'Behind',  'class' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400'],
            'overdue'   => ['label' => 'Overdue', 'class' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400'],
            'completed' => ['label' => 'Complete','class' => 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400'],
            'cancelled' => ['label' => 'Cancelled','class' => 'bg-gray-100 text-gray-600 ring-gray-500/20 dark:bg-gray-400/10 dark:text-gray-400'],
        ];
    @endphp

    {{-- Totals across the circle --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Collected', $money($group['total_paid'] ?? 0), 'Of ' . $money($group['due_to_date'] ?? 0) . ' due so far'],
            ['Outstanding', $money($group['total_unpaid'] ?? 0), ($group['members_behind'] ?? 0) . ' member(s) behind'],
            ['Pot per round', $money($group['pot_per_round'] ?? 0), 'Round ' . (($group['rounds_completed'] ?? 0) + 1) . ' of ' . ($group['rounds_total'] ?? 0)],
            ['Collection rate', $pct($group['collection_rate'] ?? 0), ($group['members_paid_up'] ?? 0) . ' of ' . ($group['members_count'] ?? 0) . ' up to date'
                . (($group['responsibility_seats_count'] ?? 0) > 0
                    ? ' · ' . $group['responsibility_seats_count'] . ' held for others'
                    : '')],
        ] as [$label, $value, $hint])
            <x-filament::section class="!p-0">
                <div class="p-4">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $value }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- Overall progress --}}
    <x-filament::section heading="Contributions">
        <div class="flex items-center gap-4">
            <div class="h-3 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                <div class="h-full rounded-full bg-primary-600 transition-all"
                     style="width: {{ $pct($group['progress'] ?? 0) }}"></div>
            </div>
            <span class="shrink-0 text-sm font-medium text-gray-600 dark:text-gray-300">
                {{ $money($group['total_paid'] ?? 0) }} / {{ $money($group['expected_total'] ?? 0) }}
            </span>
        </div>
    </x-filament::section>

    {{-- Per-member breakdown --}}
    <x-filament::section heading="Members">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4 font-medium">Member</th>
                        <th class="py-2 pr-4 font-medium">Rounds paid</th>
                        <th class="py-2 pr-4 font-medium">Paid</th>
                        <th class="py-2 pr-4 font-medium">Outstanding</th>
                        <th class="py-2 pr-4 font-medium">Last payment</th>
                        <th class="py-2 pr-4 font-medium">Status</th>
                        <th class="py-2 font-medium">Won</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($members as $m)
                        @php $b = $badge[$m['payment_status']] ?? $badge['cancelled']; @endphp
                        <tr>
                            <td class="py-3 pr-4">
                                <div class="font-medium text-gray-950 dark:text-white">
                                    {{ $m['name'] ?? '—' }}
                                    @if ($m['role'] === 'owner')
                                        <span class="ml-1 text-xs font-normal text-gray-500">· creator</span>
                                    @endif
                                </div>
                                {{-- A place held for someone with no Niya account. It pays in and
                                     can win like any other member, so it belongs in this table —
                                     but who owes the money has to be visible, or the circle reads
                                     it as a member quietly in arrears. --}}
                                @if (! empty($m['is_responsibility_seat']))
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center rounded-md bg-purple-50 px-1.5 py-0.5 text-[11px] font-medium text-purple-700 ring-1 ring-inset ring-purple-600/20 dark:bg-purple-400/10 dark:text-purple-300">
                                            Responsibility
                                        </span>
                                        @if (! empty($m['sponsor_name']))
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                paid by {{ $m['sponsor_name'] }}
                                            </span>
                                        @endif
                                        @if (! empty($m['relation']))
                                            <span class="text-xs text-gray-400 dark:text-gray-500">
                                                · {{ $m['relation'] }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $m['phone'] }}</div>
                            </td>
                            <td class="py-3 pr-4">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-20 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                        <div class="h-full rounded-full bg-primary-600" style="width: {{ $pct($m['progress']) }}"></div>
                                    </div>
                                    <span class="text-xs text-gray-600 dark:text-gray-300">
                                        {{ $m['rounds_paid'] }}/{{ $m['rounds_total'] }}
                                    </span>
                                </div>
                            </td>
                            <td class="py-3 pr-4 tabular-nums">{{ $money($m['total_paid']) }}</td>
                            <td class="py-3 pr-4 tabular-nums {{ $m['outstanding_now'] > 0 ? 'text-danger-600 dark:text-danger-400 font-medium' : '' }}">
                                {{ $money($m['outstanding_now']) }}
                            </td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">
                                {{ $m['last_payment_at'] ? \Illuminate\Support\Carbon::parse($m['last_payment_at'])->format('d M Y') : '—' }}
                            </td>
                            <td class="py-3 pr-4">
                                <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $b['class'] }}">
                                    {{ $b['label'] }}
                                </span>
                            </td>
                            <td class="py-3 text-gray-500 dark:text-gray-400">
                                {{ $m['has_won'] ? \Illuminate\Support\Carbon::parse($m['win_date'])->format('d M Y') : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-gray-500 dark:text-gray-400">
                                Nobody has joined this group yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
