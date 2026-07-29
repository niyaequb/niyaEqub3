<?php

namespace App\Filament\Resources\EqubGroups\Schemas;

use App\Enums\EqubDrawType;
use App\Enums\EqubGroupStatus;
use App\Models\EqubPackage;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class EqubGroupForm
{
    private static function convertToTipTapDoc(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) && isset($value['type']) && $value['type'] === 'doc') {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['type']) && $decoded['type'] === 'doc') {
                return $decoded;
            }
        }

        // Cast non-string scalars (int, float, bool) to string before passing to tiptap
        if (! is_string($value)) {
            $value = (string) $value;
        }

        try {
            return RichContentRenderer::make()->getEditor()->setContent($value)->getDocument();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('equb_package_id')
                    ->label(__('filament.equb_group.equb_package'))
                    ->relationship('package', 'name')
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (string|int|null $state, $set) {
                        if (! $state) {
                            return;
                        }

                        $package = EqubPackage::find($state);
                        if ($package) {
                            $set('fixed_contribution_amount', $package->fixed_contribution_amount);
                            $set('contribution_frequency_days', $package->contribution_frequency_days);
                            $set('terms_content', self::convertToTipTapDoc($package->terms_content));
                        }
                    })
                    ->preload()
                    ->getOptionLabelFromRecordUsing(fn (EqubPackage $r) => $r->name),
                    // ->getOptionLabelFromRecordUsing(fn (EqubPackage $r) => $r->name.' ('.$r->type->value.')'),
                TextInput::make('name')
                    ->label(__('filament.equb_group.name'))
                    ->required(),
                TextInput::make('fixed_contribution_amount')
                    ->label(__('filament.equb_group.fixed_contribution_amount'))
                    ->numeric()
                    ->required(),
                TextInput::make('contribution_frequency_days')
                    ->label(__('filament.equb_group.contribution_frequency_days'))
                    ->numeric()
                    ->required(),
                Select::make('duration_type')
                    ->label(__('filament.equb_group.duration_type'))
                    ->options(\App\Enums\EqubDurationType::class)
                    ->default(\App\Enums\EqubDurationType::Fixed->value)
                    ->required()
                    ->hidden()
                    ->dehydrated(),
                TextInput::make('duration_value')
                    ->label(__('filament.equb_group.duration_value'))
                    ->numeric()
                    ->required()
                    ->live(),
                    // ->required(fn ($get) => $get('duration_type') === \App\Enums\EqubDurationType::Fixed->value)
                    // ->visible(fn ($get) => $get('duration_type') === \App\Enums\EqubDurationType::Fixed->value),
                Select::make('duration_unit')
                    ->label(__('filament.equb_group.duration_unit'))
                    ->options(\App\Enums\EqubDurationUnit::class)
                    ->default(\App\Enums\EqubDurationUnit::Days->value)
                    ->required()
                    ->live(),
                    // ->required(fn ($get) => $get('duration_type') === \App\Enums\EqubDurationType::Fixed->value)
                    // ->visible(fn ($get) => $get('duration_type') === \App\Enums\EqubDurationType::Fixed->value),
                // DateTimePicker::make('registration_open_at')
                //     ->label(__('filament.equb_group.registration_open_at'))
                //     ->required(),
                // DateTimePicker::make('registration_close_at')
                //     ->label(__('filament.equb_group.registration_close_at'))
                //     ->nullable(),
                DateTimePicker::make('equb_start_date')
                    ->label(__('filament.equb_group.equb_start_date'))
                    ->disabled(fn ($record) => $record && ! in_array($record->status, [
                        EqubGroupStatus::Draft,
                        EqubGroupStatus::Registration,
                    ]))
                    ->dehydrated()
                    ->nullable()
                    ->helperText(__('filament.equb_group.start_date_helper')),
                DateTimePicker::make('equb_end_date')
                    ->label(__('filament.equb_group.equb_end_date'))
                    ->nullable(),
                RichEditor::make('terms_content')
                    ->label(__('filament.equb_group.terms_content'))
                    ->nullable()
                    ->columnSpanFull(),
                Select::make('status')
                    ->label(__('filament.equb_group.status'))
                    ->options(collect(EqubGroupStatus::cases())->mapWithKeys(
                        fn (EqubGroupStatus $s): array => [
                            $s->value => __("filament.equb_group.status_{$s->value}")
                        ]
                    )->toArray())
                    ->default(EqubGroupStatus::Draft->value)
                    ->hiddenOn('create')
                    ->required(),
                Select::make('draw_type')
                    ->label(__('filament.equb_group.draw_type'))
                    ->options(collect(EqubDrawType::cases())->mapWithKeys(
                        fn (EqubDrawType $d): array => [
                            $d->value => __("filament.equb_group.draw_type_{$d->value}")
                        ]
                    )->toArray())
                    ->default(EqubDrawType::Manual->value)
                    ->required(),
                Toggle::make('is_locked')
                    ->label(__('filament.equb_group.is_locked'))
                    ->default(false),
            ]);
    }
}
