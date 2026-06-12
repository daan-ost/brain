<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserLicense;
use Illuminate\Database\Eloquent\Builder;

class NewsletterSegmentService
{
    public const SEGMENT_ALL = 'all';
    public const SEGMENT_PAYING = 'paying';
    public const SEGMENT_FREE = 'free';
    public const SEGMENT_NL = 'nl';
    public const SEGMENT_EN = 'en';
    public const SEGMENT_RECENT_SIGNUP_90 = 'recent_signup_90';
    public const SEGMENT_RECENT_LOGIN_180 = 'recent_login_180';

    /**
     * @return array<string, string> key => Dutch label
     */
    public function availableSegments(): array
    {
        return [
            self::SEGMENT_ALL => 'Alle ingeschreven gebruikers',
            self::SEGMENT_PAYING => 'Betalende gebruikers',
            self::SEGMENT_FREE => 'Gratis gebruikers',
            self::SEGMENT_NL => 'Taal: Nederlands',
            self::SEGMENT_EN => 'Taal: Engels',
            self::SEGMENT_RECENT_SIGNUP_90 => 'Aangemeld < 90 dagen',
            self::SEGMENT_RECENT_LOGIN_180 => 'Ingelogd < 180 dagen',
        ];
    }

    public function isValid(string $segmentKey): bool
    {
        return array_key_exists($segmentKey, $this->availableSegments());
    }

    public function label(string $segmentKey): string
    {
        return $this->availableSegments()[$segmentKey] ?? $segmentKey;
    }

    /**
     * Builder voor alle eligible users in het segment.
     * Altijd gebaseerd op User::newsletterSubscribed() zodat unsubscribed/bounced/unverified eruit blijven.
     */
    public function query(string $segmentKey): Builder
    {
        $query = User::newsletterSubscribed();

        return match ($segmentKey) {
            self::SEGMENT_ALL => $query,

            self::SEGMENT_PAYING => $query->whereHas('userLicenses', function (Builder $q) {
                $q->where('status', UserLicense::STATUS_ACTIVE);
            }),

            self::SEGMENT_FREE => $query->whereDoesntHave('userLicenses', function (Builder $q) {
                $q->where('status', UserLicense::STATUS_ACTIVE);
            }),

            self::SEGMENT_NL => $query->where('preferred_language', 'nl'),

            self::SEGMENT_EN => $query->where('preferred_language', 'en'),

            self::SEGMENT_RECENT_SIGNUP_90 => $query->where('created_at', '>=', now()->subDays(90)),

            self::SEGMENT_RECENT_LOGIN_180 => $query->whereNotNull('last_login_at')
                ->where('last_login_at', '>=', now()->subDays(180)),

            default => $query->whereRaw('1 = 0'),
        };
    }

    public function count(string $segmentKey): int
    {
        if (! $this->isValid($segmentKey)) {
            return 0;
        }

        return $this->query($segmentKey)->count();
    }
}
