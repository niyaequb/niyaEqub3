<?php

namespace App\Filament\Resources\MemberEqubGroups\Tables;

use App\Enums\EqubGroupModerationStatus;
use App\Enums\EqubGroupStatus;
use App\Filament\Resources\MemberEqubGroups\MemberEqubGroupResource;
use App\Models\EqubGroup;
use App\Services\GroupDrawService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MemberEqubGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label(__('filament.equb_group.name'))->searchable()->sortable(),
                TextColumn::make('owner.full_name')->label(__('filament.member_equb_group.owner'))->searchable(),
                TextColumn::make('invite_code')->label(__('filament.member_equb_group.invite_code'))->copyable()->badge()->color('gray'),
                TextColumn::make('package.name')->label(__('filament.equb_group.package'))->toggleable(),
                TextColumn::make('fixed_contribution_amount')->label(__('filament.equb_group.contribution'))->money('ETB')->sortable(),
                TextColumn::make('current_members_count')->label(__('filament.equb_group.current_members_count'))->sortable(),

                // How much of that head-count is places held for people with
                // no Niya account. Already inside current_members_count — this
                // is the breakdown, so an admin can see at a glance that a
                // group of 8 is really 3 accounts carrying 5 places.
                TextColumn::make('responsibility_seats_count')
                    ->label(__('filament.member_equb_group.responsibility_column'))
                    ->badge()
                    ->color('info')
                    ->toggleable()
                    ->counts([
                        'memberships as responsibility_seats_count' => fn ($q) => $q->whereNull('member_id'),
                    ])
                    ->formatStateUsing(fn ($state): string => ((int) $state) > 0 ? (string) $state : '—'),
                TextColumn::make('has_won_round')
                    ->label(__('filament.member_equb_group.winning_status'))
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn ($state, EqubGroup $record): string => $state
                        ? __('filament.member_equb_group.won_on', [
                            'date' => $record->won_round_at?->format('d M Y') ?? '',
                        ])
                        : __('filament.member_equb_group.not_won'))
                    ->color(fn ($state): string => $state ? 'success' : 'gray')
                    ->icon(fn ($state): string => $state ? 'heroicon-m-trophy' : 'heroicon-m-clock'),
                TextColumn::make('moderation_status')
                    ->label(__('filament.member_equb_group.moderation'))
                    ->badge()
                    ->color(fn (?EqubGroupModerationStatus $state): string => $state?->color() ?? 'gray'),
                TextColumn::make('status')->label(__('filament.equb_group.status'))->badge()->sortable(),
                TextColumn::make('created_at')->label(__('filament.user.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('has_won_round')
                    ->label(__('filament.member_equb_group.winning_status'))
                    ->trueLabel(__('filament.member_equb_group.filter_won'))
                    ->falseLabel(__('filament.member_equb_group.filter_not_won'))
                    ->placeholder(__('filament.member_equb_group.filter_all')),

                SelectFilter::make('moderation_status')
                    ->label(__('filament.member_equb_group.moderation'))
                    ->options(collect(EqubGroupModerationStatus::cases())
                        ->mapWithKeys(fn (EqubGroupModerationStatus $s): array => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('status')
                    ->label(__('filament.equb_group.status'))
                    ->options(collect(EqubGroupStatus::cases())
                        ->mapWithKeys(fn (EqubGroupStatus $s): array => [$s->value => __("filament.equb_group.status_{$s->value}")])
                        ->toArray()),
            ])
            ->recordActions([
                // Kept out of the dropdown: these are the two things an admin
                // reaches for constantly.
                Action::make('ledger')
                    ->label(__('filament.member_equb_group.view_ledger'))
                    ->icon('heroicon-o-banknotes')
                    ->color('gray')
                    ->button()
                    ->outlined()
                    ->url(fn (EqubGroup $record): string => MemberEqubGroupResource::getUrl('ledger', ['record' => $record])),

                EditAction::make()
                    ->label(__('filament.member_equb_group.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->button()
                    ->outlined(),

                ActionGroup::make([
                    Action::make('approve')
                        ->label(__('filament.member_equb_group.approve'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (EqubGroup $record): bool => $record->moderation_status !== EqubGroupModerationStatus::Approved)
                        ->action(function (EqubGroup $record): void {
                            $record->update([
                                'moderation_status' => EqubGroupModerationStatus::Approved,
                                'approved_at' => now(),
                                'approved_by_admin_id' => Auth::id(),
                                'rejection_reason' => null,
                            ]);

                            Notification::make()->title(__('filament.member_equb_group.approved_notice'))->success()->send();
                        }),

                    Action::make('reject')
                        ->label(__('filament.member_equb_group.reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->schema([
                            Textarea::make('reason')
                                ->label(__('filament.member_equb_group.reject_reason'))
                                ->required()
                                ->maxLength(300),
                        ])
                        ->visible(fn (EqubGroup $record): bool => $record->moderation_status !== EqubGroupModerationStatus::Rejected)
                        ->action(function (EqubGroup $record, array $data): void {
                            $record->update([
                                'moderation_status' => EqubGroupModerationStatus::Rejected,
                                'rejection_reason' => $data['reason'],
                                'approved_by_admin_id' => Auth::id(),
                            ]);

                            Notification::make()->title(__('filament.member_equb_group.rejected_notice'))->warning()->send();
                        }),

                    Action::make('runDraw')
                        ->label(__('filament.member_equb_group.run_draw'))
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->visible(fn (EqubGroup $record): bool => $record->status === EqubGroupStatus::Running)
                        ->schema(fn (EqubGroup $record): array => [
                            TextInput::make('winners_count')
                                ->label(__('filament.member_equb_group.winners_this_round'))
                                ->numeric()
                                ->minValue(1)
                                ->default($record->winnersForNextRound())
                                ->helperText(__('filament.member_equb_group.winners_hint')),
                            CheckboxList::make('membership_ids')
                                ->label(__('filament.member_equb_group.pick_winners'))
                                ->options(fn (): array => app(GroupDrawService::class)
                                    ->eligibleMemberships($record)
                                    // displayName() covers places held for
                                    // someone with no Niya account, which have
                                    // no member row and used to render here as
                                    // a bare "Member #".
                                    ->mapWithKeys(fn ($m): array => [
                                        $m->id => $m->isResponsibilitySeat()
                                            ? $m->displayName().' — '.__('filament.member_equb_group.responsibility_paid_by', [
                                                'name' => $m->sponsor?->full_name ?? '',
                                            ])
                                            : $m->displayName(),
                                    ])
                                    ->toArray())
                                ->columns(2)
                                ->helperText(__('filament.member_equb_group.pick_winners_hint')),
                        ])
                        ->action(function (EqubGroup $record, array $data): void {
                            $result = app(GroupDrawService::class)->runRound(
                                $record,
                                Auth::id(),
                                $data['membership_ids'] ?? [],
                                filled($data['winners_count'] ?? null) ? (int) $data['winners_count'] : null,
                            );

                            if (! $result['success']) {
                                Notification::make()->title($result['message'])->danger()->send();

                                return;
                            }

                            $names = collect($result['winners'])->pluck('name')->filter()->implode(', ');

                            Notification::make()
                                ->title(__('filament.member_equb_group.draw_done'))
                                ->body($names)
                                ->success()
                                ->send();
                        }),
                    DeleteAction::make(),
                ])->iconButton(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
