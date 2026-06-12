<?php

namespace App\Policies;

use App\Models\DemoItem;
use App\Models\User;

class DemoItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DemoItem $demoItem): bool
    {
        return $user->id === $demoItem->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, DemoItem $demoItem): bool
    {
        return $user->id === $demoItem->user_id;
    }

    public function delete(User $user, DemoItem $demoItem): bool
    {
        return $user->id === $demoItem->user_id;
    }
}
