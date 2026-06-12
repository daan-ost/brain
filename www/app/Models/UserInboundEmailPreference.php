<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserInboundEmailPreference extends Model
{
    protected $fillable = [
        'user_id',
        'inbound_enabled',
        'verify_sender',
        'available_actions',
    ];

    protected $casts = [
        'inbound_enabled' => 'boolean',
        'verify_sender' => 'boolean',
        'available_actions' => 'array',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($preference) {
            // Generate unique tokens for each action when creating preferences
            if (empty($preference->available_actions)) {
                $preference->available_actions = self::generateDefaultActions();
            }
        });
    }

    /**
     * Get the user that owns this preference
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate default actions with unique tokens
     */
    public static function generateDefaultActions(): array
    {
        $domain = config('inbound.email_domain', 'inbound.example.com');
        $actions = config('inbound.available_actions', ['merge', 'convert']);

        $result = [];
        foreach ($actions as $action) {
            $token = self::generateUniqueToken();
            $result[$action] = [
                'token' => $token,
                'email' => "{$action}+{$token}@{$domain}",
                'enabled' => true,
            ];
        }

        return $result;
    }

    /**
     * Generate a unique token for email addresses
     */
    protected static function generateUniqueToken(): string
    {
        return strtolower(Str::random(12));
    }

    /**
     * Get the email address for a specific action
     */
    public function getEmailForAction(string $action): ?string
    {
        return $this->available_actions[$action]['email'] ?? null;
    }

    /**
     * Get all action email addresses
     */
    public function getAllActionEmails(): array
    {
        $emails = [];
        foreach ($this->available_actions ?? [] as $action => $config) {
            if ($config['enabled'] ?? false) {
                $emails[$action] = $config['email'];
            }
        }

        return $emails;
    }

    /**
     * Check if an action is enabled
     */
    public function isActionEnabled(string $action): bool
    {
        return $this->available_actions[$action]['enabled'] ?? false;
    }

    /**
     * Enable an action
     */
    public function enableAction(string $action): void
    {
        $actions = $this->available_actions ?? [];
        if (isset($actions[$action])) {
            $actions[$action]['enabled'] = true;
            $this->update(['available_actions' => $actions]);
        }
    }

    /**
     * Disable an action
     */
    public function disableAction(string $action): void
    {
        $actions = $this->available_actions ?? [];
        if (isset($actions[$action])) {
            $actions[$action]['enabled'] = false;
            $this->update(['available_actions' => $actions]);
        }
    }

    /**
     * Find preference by action token
     *
     * Uses MySQL JSON_SEARCH for efficient database-level filtering
     * instead of loading all records into PHP memory.
     * JSON structure: {"merge": {"token": "xxx", ...}, "convert": {"token": "yyy", ...}}
     */
    public static function findByToken(string $token): ?self
    {
        return self::where('inbound_enabled', true)
            ->whereRaw(
                "JSON_SEARCH(available_actions, 'one', ?, NULL, '$.*.token') IS NOT NULL",
                [$token]
            )
            ->first();
    }

    /**
     * Get the action type for a given token
     */
    public function getActionForToken(string $token): ?string
    {
        foreach ($this->available_actions ?? [] as $action => $config) {
            if (($config['token'] ?? null) === $token) {
                return $action;
            }
        }

        return null;
    }
}
