<?php

namespace App\Filament\Resources\EqubGroups\RelationManagers;

use App\Filament\Resources\Members\MemberResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Memberships';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('member.full_name')
                    ->label('Member')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->member ? MemberResource::getUrl('equbs', ['record' => $record->member]) : null)
                    ->openUrlInNewTab(false),
                TextColumn::make('member.user.phone')->label('Phone')->searchable(),
                TextColumn::make('cohort.name')->label('Cohort')->sortable(),
                TextColumn::make('contribution_amount')->label('Amount')->money('ETB')->sortable(),
                TextColumn::make('contribution_frequency_days')->label('Freq. Days'),
                TextColumn::make('join_date')->label('Join Date')->dateTime()->sortable(),
                TextColumn::make('calculated_end_date')->label('End Date')->dateTime()->toggleable(),
                TextColumn::make('draw_position')->label('Position')->toggleable(),
                IconColumn::make('has_won')->label('Won')->boolean(),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->defaultSort('join_date', 'desc')
            ->toolbarActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);

        \App\Filament\Support\TableExportHelper::attach($table, 'Equb Memberships');

        return $table;
    }

    public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema
    {
        return $form
            ->schema([
                Select::make('member_id')
                    ->relationship('member', 'full_name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('cohort_id')
                    ->relationship('cohort', 'name', fn ($query) => $query->where('equb_group_id', $this->getOwnerRecord()->id))
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('contribution_amount')
                    ->numeric()
                    ->required()
                    ->default(fn () => $this->getOwnerRecord()->fixed_contribution_amount),
                TextInput::make('contribution_frequency_days')
                    ->numeric()
                    ->required()
                    ->default(fn () => $this->getOwnerRecord()->contribution_frequency_days),
                DatePicker::make('join_date')
                    ->default(now())
                    ->required(),
                Toggle::make('has_won')
                    ->default(false),
                Select::make('status')
                    ->options(\App\Enums\EqubMembershipStatus::class)
                    ->default(\App\Enums\EqubMembershipStatus::Active)
                    ->required(),
            ]);
    }
}
