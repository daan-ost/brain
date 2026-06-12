<?php

namespace App\Services;

use App\Enums\DemoItemStatus;
use App\Models\DemoItem;
use App\Models\User;

class DemoItemService
{
    public function create(User $user, array $data): DemoItem
    {
        return $user->demoItems()->create($data);
    }

    public function update(DemoItem $item, array $data): DemoItem
    {
        $item->update($data);

        return $item->fresh();
    }

    public function delete(DemoItem $item): bool
    {
        return $item->delete();
    }

    public function transitionStatus(DemoItem $item, DemoItemStatus $newStatus): DemoItem
    {
        $item->transitionTo($newStatus);

        return $item->fresh();
    }

    public function bulkDelete(array $ids, User $user): int
    {
        return DemoItem::whereIn('id', $ids)
            ->where('user_id', $user->id)
            ->delete();
    }

    public function bulkTransition(array $ids, User $user, DemoItemStatus $status): int
    {
        $items = DemoItem::whereIn('id', $ids)
            ->where('user_id', $user->id)
            ->get();

        $count = 0;
        foreach ($items as $item) {
            if ($item->status->canTransitionTo($status)) {
                $item->transitionTo($status);
                $count++;
            }
        }

        return $count;
    }

    public function getSummary(User $user): array
    {
        $items = $user->demoItems();

        return [
            'total' => $items->count(),
            'draft' => (clone $items)->where('status', DemoItemStatus::Draft)->count(),
            'active' => (clone $items)->where('status', DemoItemStatus::Active)->count(),
            'completed' => (clone $items)->where('status', DemoItemStatus::Completed)->count(),
            'cancelled' => (clone $items)->where('status', DemoItemStatus::Cancelled)->count(),
            'overdue' => (clone $items)->overdue()->count(),
            'total_amount' => (clone $items)->sum('amount'),
        ];
    }
}
