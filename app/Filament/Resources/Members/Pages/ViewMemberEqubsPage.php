<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\Concerns\HasMemberEqubManagement;
use App\Filament\Resources\Members\MemberResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class ViewMemberEqubsPage extends Page
{
    use HasMemberEqubManagement;
    use InteractsWithRecord;

    protected static string $resource = MemberResource::class;

    protected string $view = 'filament.resources.members.pages.view-member-equbs';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->record->loadMissing('equbMemberships.equbGroup.package');
        if ($this->equbCalendarYear === null) {
            $this->equbCalendarYear = (int) now()->format('Y');
        }
        if ($this->equbCalendarMonth === null) {
            $this->equbCalendarMonth = (int) now()->format('n');
        }
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Manage Equbs — '.$this->getRecord()->full_name;
    }

    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();
        $record = $this->getRecord();

        return [
            $resource::getUrl('index') => $resource::getPluralModelLabel(),
            $resource::getUrl('view', ['record' => $record]) => $record->full_name ?? $record->getKey(),
            'Equbs' => 'Equbs',
        ];
    }
}
