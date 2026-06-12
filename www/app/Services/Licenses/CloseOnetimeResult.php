<?php

namespace App\Services\Licenses;

use Carbon\Carbon;

final readonly class CloseOnetimeResult
{
    public function __construct(
        public bool $changed,
        public ?string $status = null,
        public int $remainingAdjusted = 0,
        public int $newBalance = 0,
        public ?Carbon $endedAt = null
    ) {}
}
