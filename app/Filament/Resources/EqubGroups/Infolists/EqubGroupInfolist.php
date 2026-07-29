<?php

namespace App\Filament\Resources\EqubGroups\Infolists;

use App\Models\EqubGroup;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid as ComponentsGrid;
use Filament\Schemas\Components\Group as ComponentsGroup;
use Filament\Schemas\Components\Section as ComponentsSection;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class EqubGroupInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                ComponentsSection::make(__('filament.equb_group.overview'))
                    ->description(__('filament.equb_group.overview_description'))
                    ->icon('heroicon-o-information-circle')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('filament.equb_group.group_name'))
                            ->weight(FontWeight::Bold)
                            ->size('lg'),
                        TextEntry::make('package.name')
                            ->label(__('filament.equb_group.package'))
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('status')
                            ->label(__('filament.equb_group.current_status'))
                            ->badge(),
                    ]),

                ComponentsGrid::make(2)
                    ->schema([
                        ComponentsSection::make(__('filament.equb_group.financial_details'))
                            ->columnSpan(1)
                            ->icon('heroicon-o-banknotes')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('fixed_contribution_amount')
                                    ->label(__('filament.equb_group.contribution_amount'))
                                    ->money('ETB')
                                    ->weight(FontWeight::Bold)
                                    ->color('primary'),
                                TextEntry::make('contribution_frequency_days')
                                    ->label(__('filament.equb_group.frequency'))
                                    ->suffix(' Days'),
                                // TextEntry::make('total_amount_per_draw')
                                //     ->label(__('filament.equb_group.total_prize_pool'))
                                //     ->money('ETB')
                                //     ->weight(FontWeight::Bold)
                                //     ->color('success')
                                //     ->columnSpanFull(),
                            ]),

                        ComponentsSection::make(__('filament.equb_group.participation_controls'))
                            ->columnSpan(1)
                            ->icon('heroicon-o-users')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('current_members_count')
                                    ->label(__('filament.equb_group.joined_members'))
                                    ->weight(FontWeight::Bold),
                                IconEntry::make('is_locked')
                                    ->label(__('filament.equb_group.is_locked'))
                                    ->boolean(),
                                TextEntry::make('draw_type')
                                    ->label(__('filament.equb_group.draw_type'))
                                    ->badge()
                                    ->columnSpanFull(),
                            ]),
                    ]),

                ComponentsSection::make(__('filament.equb_group.timeline_registration'))
                    ->icon('heroicon-o-calendar-days')
                    ->columns(4)
                    ->schema([
                        ComponentsGroup::make([
                            TextEntry::make('registration_open_at')
                                ->label(__('filament.equb_group.reg_opens'))
                                ->dateTime()
                                ->color('gray'),
                            TextEntry::make('registration_close_at')
                                ->label(__('filament.equb_group.reg_closes'))
                                ->dateTime()
                                ->color('gray'),
                        ])->columnSpan(2)->columns(2),

                        ComponentsGroup::make([
                            TextEntry::make('equb_start_date')
                                ->label(__('filament.equb_group.equb_start'))
                                ->dateTime()
                                ->weight(FontWeight::SemiBold),
                            TextEntry::make('equb_end_date')
                                ->label(__('filament.equb_group.equb_end'))
                                ->dateTime()
                                ->weight(FontWeight::SemiBold),
                        ])->columnSpan(2)->columns(2),
                    ]),

                ComponentsSection::make(__('filament.equb_group.terms_conditions'))
                    ->icon('heroicon-o-document-text')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('terms_content')
                            ->label('')
                            ->html()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
