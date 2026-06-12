<?php

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Component;

class LiveMonitor extends Component
{
    public function render(): View
    {
        return view('livewire.live-monitor', [
            'events' => $this->getDemoEvents(),
        ]);
    }

    /** @return list<array{time: string, type: string, message: string}> */
    private function getDemoEvents(): array
    {
        return [
            ['time' => '14:32:01', 'type' => 'info', 'message' => 'System initialized'],
            ['time' => '14:32:05', 'type' => 'info', 'message' => 'GPS lock acquired'],
            ['time' => '14:32:12', 'type' => 'info', 'message' => 'Telemetry stream started'],
            ['time' => '14:33:45', 'type' => 'warning', 'message' => 'Wind speed above threshold'],
            ['time' => '14:34:10', 'type' => 'info', 'message' => 'Waypoint 1 reached'],
            ['time' => '14:35:22', 'type' => 'info', 'message' => 'Waypoint 2 reached'],
            ['time' => '14:36:01', 'type' => 'error', 'message' => 'Sensor calibration warning'],
            ['time' => '14:36:30', 'type' => 'info', 'message' => 'Sensor recalibrated'],
            ['time' => '14:37:15', 'type' => 'info', 'message' => 'Waypoint 3 reached'],
            ['time' => '14:38:00', 'type' => 'info', 'message' => 'Altitude adjusted'],
            ['time' => '14:39:10', 'type' => 'warning', 'message' => 'Battery below 90%'],
            ['time' => '14:40:22', 'type' => 'info', 'message' => 'Return path calculated'],
        ];
    }
}
