<?php

namespace App\Filament\Resources\EqubDraws\Pages;

use App\Filament\Resources\EqubDraws\EqubDrawResource;
use App\Models\EqubDraw;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

/**
 * One round, with the pool it was drawn from.
 *
 * The infolist covers the result and the arithmetic. This page adds the part
 * that cannot be expressed as fields: the entrants themselves, each with the
 * weight they held and the reason for it, and — the half that matters more —
 * everybody who was kept out, named, with what they owed on the day.
 *
 * That second list is the one an operator needs. "No eligible members" and
 * "eleven members, nine of them two rounds behind" are the same fact and only
 * one of them can be acted on.
 */
class ViewEqubDraw extends ViewRecord
{
    protected static string $resource = EqubDrawResource::class;

    protected string $view = 'filament.resources.equb-draws.view';

    public function getTitle(): string
    {
        /** @var EqubDraw $record */
        $record = $this->record;

        return __('filament.equb_draw.round_title', [
            'round' => $record->round_number ?? $record->id,
            'equb' => $record->equbGroup?->name ?? '',
        ]);
    }

    public function getSubheading(): ?string
    {
        /** @var EqubDraw $record */
        $record = $this->record;

        return $record->draw_date?->translatedFormat('l, d F Y').' · '.$record->draw_date?->format('H:i');
    }

    /**
     * Entrants who were in the running, heaviest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function eligibleEntries(): Collection
    {
        return $this->snapshotEntries()
            ->filter(fn (array $e): bool => (bool) ($e['eligible'] ?? false))
            ->sortByDesc(fn (array $e): float => (float) ($e['weight'] ?? 0))
            ->values();
    }

    /**
     * Everyone kept out, and why.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function excludedEntries(): Collection
    {
        return $this->snapshotEntries()
            ->filter(fn (array $e): bool => ! ($e['eligible'] ?? false))
            ->sortByDesc(fn (array $e): float => (float) ($e['arrears'] ?? 0))
            ->values();
    }

    /**
     * Group Equb rounds record families rather than individual places.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function snapshotGroups(): Collection
    {
        /** @var EqubDraw $record */
        $record = $this->record;

        return collect($record->eligibility_snapshot['groups'] ?? []);
    }

    public function isGroupLevel(): bool
    {
        /** @var EqubDraw $record */
        $record = $this->record;

        return ($record->eligibility_snapshot['level'] ?? null) === 'group';
    }

    /** The id of the membership that won, so the list can mark it. */
    public function winningMembershipIds(): array
    {
        /** @var EqubDraw $record */
        $record = $this->record;

        $fromSnapshot = collect($record->eligibility_snapshot['winners'] ?? [])
            ->pluck('membership_id')
            ->filter()
            ->all();

        return $fromSnapshot !== []
            ? $fromSnapshot
            : array_filter([$record->winner_membership_id]);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function snapshotEntries(): Collection
    {
        /** @var EqubDraw $record */
        $record = $this->record;

        return $record->snapshotEntries();
    }

    /** The rules the round ran under — not necessarily today's. */
    public function rulesInForce(): array
    {
        /** @var EqubDraw $record */
        $record = $this->record;

        return $record->snapshotRules();
    }
}
