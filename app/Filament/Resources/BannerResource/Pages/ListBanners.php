<?php

namespace App\Filament\Resources\BannerResource\Pages;

use App\Filament\Resources\BannerResource;
use App\Models\Banner;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBanners extends ListRecords
{
    protected static string $resource = BannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // How long each banner stays on screen in the apps before sliding
            // to the next. Lives here, next to the banners it controls, rather
            // than buried in Settings.
            Actions\Action::make('slideDuration')
                ->label(fn (): string => 'Slide duration ('.Banner::slideDuration().'s)')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->modalHeading('Banner slide duration')
                ->modalDescription('How many seconds each banner stays on screen in the app before it slides to the next one. Takes effect the next time the app loads its home screen.')
                ->modalSubmitActionLabel('Save')
                ->modalWidth('md')
                ->fillForm(fn (): array => [
                    'duration' => Banner::slideDuration(),
                ])
                ->schema([
                    TextInput::make('duration')
                        ->label('Seconds per banner')
                        ->numeric()
                        ->integer()
                        ->required()
                        ->minValue(Banner::MIN_SLIDE_DURATION)
                        ->maxValue(Banner::MAX_SLIDE_DURATION)
                        ->suffix('seconds')
                        ->helperText('Between '.Banner::MIN_SLIDE_DURATION.' and '.Banner::MAX_SLIDE_DURATION.'. Default is '.Banner::DEFAULT_SLIDE_DURATION.'. A larger number means slower sliding.'),
                ])
                ->action(function (array $data): void {
                    Banner::setSlideDuration((int) $data['duration']);

                    Notification::make()
                        ->title('Slide duration saved')
                        ->body('Banners will now stay on screen for '.Banner::slideDuration().' seconds.')
                        ->success()
                        ->send();
                }),

            Actions\CreateAction::make(),
        ];
    }
}
