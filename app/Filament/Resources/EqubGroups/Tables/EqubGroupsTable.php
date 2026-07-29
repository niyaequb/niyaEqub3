<?php

namespace App\Filament\Resources\EqubGroups\Tables;

use App\Enums\EqubGroupStatus;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use App\Filament\Support\TableExportHelper;

class EqubGroupsTable
{
    public static function configure(Table $table): Table
    {
        $table
            // Member-created groups have their own resource (MemberEqubGroupResource).
            ->modifyQueryUsing(fn ($query) => $query->whereNull('owner_member_id'))
            ->columns([
                TextColumn::make('id')->label(__('filament.user.id'))->sortable(),
                TextColumn::make('name')->label(__('filament.equb_group.name'))->searchable()->sortable(),
                TextColumn::make('package.name')->label(__('filament.equb_group.package'))->searchable()->sortable(),
                TextColumn::make('fixed_contribution_amount')->label(__('filament.equb_group.contribution'))->money('ETB')->sortable(),
                TextColumn::make('registration_open_at')->label(__('filament.equb_group.registration_open_at'))->dateTime()->sortable(),
                TextColumn::make('registration_close_at')->label(__('filament.equb_group.registration_close_at'))->dateTime()->toggleable(),
                TextColumn::make('equb_start_date')->label(__('filament.equb_group.equb_start_date'))->dateTime()->toggleable(),
                TextColumn::make('equb_end_date')->label(__('filament.equb_group.equb_end_date'))->dateTime()->toggleable(),
                TextColumn::make('current_members_count')->label(__('filament.equb_group.current_members_count'))->sortable(),
                TextColumn::make('status')->label(__('filament.equb_group.status'))->badge()->sortable(),
                IconColumn::make('is_locked')->label(__('filament.equb_group.is_locked'))->boolean()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('filament.equb_group.status'))->options(
                    collect(EqubGroupStatus::cases())->mapWithKeys(fn (EqubGroupStatus $s): array => [
                        $s->value => __("filament.equb_group.status_{$s->value}")
                    ])->toArray()
                ),
                SelectFilter::make('draw_type')->label(__('filament.equb_group.draw_type'))->options([
                    'manual' => __('filament.equb_group.draw_type_manual'),
                    'automatic' => __('filament.equb_group.draw_type_automatic'),
                    'both' => __('filament.equb_group.draw_type_both'),
                ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.index'))),
                    EditAction::make()
                        ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.edit'))),
                    DeleteAction::make()
                        ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.delete'))),
                ])->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool =>
                        Auth::check() &&
                         ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.delete'))),
                ]),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Equb Groups');

        return $table;
    }
}
