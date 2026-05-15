<?php

namespace App\Enums;

enum EventStructure: string
{
    case Standalone = 'standalone';
    case ParentProgram = 'parent_program';
    case ChildEvent = 'child_event';

    public function label(): string
    {
        return match ($this) {
            self::Standalone => __('Standalone Event'),
            self::ParentProgram => __('Parent Program'),
            self::ChildEvent => __('Child Event'),
        };
    }

    public function isSchedulable(): bool
    {
        return $this !== self::ParentProgram;
    }

    public function isDiscoverable(): bool
    {
        return $this !== self::ParentProgram;
    }
}
