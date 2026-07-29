<?php

namespace App\Filament\Resources\EqubMemberships\Schemas;

use App\Enums\EqubMembershipStatus;
use App\Models\EqubGroup;
use App\Models\Member;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EqubMembershipForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('equb_group_id')
                    ->label('Equb Group')
                    ->relationship(
                        name: 'equbGroup',
                        titleAttribute: 'id',
                        modifyQueryUsing: fn ($q) => ($q ?? EqubGroup::query())->with('package')->orderBy('id', 'desc')
                    )
                    ->getOptionLabelFromRecordUsing(fn (EqubGroup $r) => ($r->name ?? 'Group #'.$r->id).' - '.($r->package?->name ?? ''))
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'full_name')
                    ->getOptionLabelFromRecordUsing(fn (Member $r) => $r->full_name.' ('.($r->user?->phone ?? '').')')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('contribution_amount')
                    ->label('Contribution Amount')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('contribution_frequency_days')
                    ->label('Contribution Frequency (Days)')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                DateTimePicker::make('join_date')
                    ->label('Join Date')
                    ->default(now())
                    ->required(),
                DateTimePicker::make('calculated_end_date')
                    ->label('Calculated End Date')
                    ->nullable(),
                TextInput::make('draw_position')
                    ->label('Draw Position')
                    ->numeric()
                    ->minValue(1)
                    ->nullable(),
                Select::make('status')
                    ->label('Status')
                    ->options(collect(EqubMembershipStatus::cases())->mapWithKeys(
                        fn (EqubMembershipStatus $s): array => [$s->value => $s->name]
                    )->toArray())
                    ->default(EqubMembershipStatus::Active->value)
                    ->required(),
            ]);
    }
}
