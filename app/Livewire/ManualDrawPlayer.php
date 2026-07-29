<?php

namespace App\Livewire;

use App\Models\EqubGroup;
use App\Services\EqubDrawService;
use Filament\Notifications\Notification;
use Livewire\Component;

class ManualDrawPlayer extends Component
{
    public $equbGroupId;
    public $isOpen = false;
    public $state = 'spinning'; // spinning, result
    public $winnerId = null;
    public $winners = [];
    public $drawExecuted = false;
    public $timerDelay = 30;

    protected $listeners = ['start-manual-draw' => 'openPlayer'];

    public function openPlayer($equbGroupId)
    {
        $this->equbGroupId = $equbGroupId;
        $this->isOpen = true;
        $this->state = 'spinning';
        $this->winnerId = null;
        $this->drawExecuted = false;
        $this->timerDelay = config('app.draw_delay', 30);
    }

    public function startDraw()
    {
        $result = app(EqubDrawService::class)->runRemainingDrawsForToday(
            $this->equbGroupId,
            auth()->id()
        );

        if ($result['success']) {
            $this->winners = $result['winners'] ?? [];
            $this->winnerId = !empty($result['draws']) ? $result['draws'][0]->winner_membership_id : null;
            $this->drawExecuted = true;
            $this->dispatch('draw-completed', winners: $this->winners);
        } else {
            $this->resetDraw();
            Notification::make()
                ->title($result['message'] ?? 'Draw failed.')
                ->danger()
                ->send();
        }
    }

    public function showResult()
    {
        if ($this->drawExecuted) {
            $this->state = 'result';
        }
    }

    public function resetDraw()
    {
        $this->isOpen = false;
        $this->state = 'spinning';
        $this->winnerId = null;
        $this->winners = [];
        $this->drawExecuted = false;
    }

    public function render()
    {
        return view('livewire.manual-draw-player');
    }
}
