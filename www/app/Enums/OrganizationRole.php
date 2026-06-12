<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Reviewer = 'reviewer';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match($this) {
            self::Owner    => 'Admin',
            self::Editor   => 'Editor',
            self::Reviewer => 'Reviewer',
            self::Viewer   => 'Viewer',
        };
    }

    public function canManage(): bool
    {
        return $this === self::Owner;
    }
}
