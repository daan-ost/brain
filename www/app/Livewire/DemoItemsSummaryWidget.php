<?php

namespace App\Livewire;

use App\Models\DemoItem;
use App\Services\DemoItemService;
use Livewire\Component;

class DemoItemsSummaryWidget extends Component
{
    public function render()
    {
        $summary = app(DemoItemService::class)->getSummary(auth()->user());
        $recentItems = DemoItem::forUser(auth()->id())
            ->latest()
            ->limit(5)
            ->get();

        return view('livewire.demo-items-summary-widget', [
            'summary' => $summary,
            'recentItems' => $recentItems,
        ]);
    }
}
