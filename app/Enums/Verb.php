<?php

namespace App\Enums;

enum Verb: string
{
    case Look = 'look';
    case Use = 'use';
    case Take = 'take';
    case Talk = 'talk';

    public function label(): string
    {
        return match ($this) {
            self::Look => 'Look at',
            self::Use => 'Use',
            self::Take => 'Take',
            self::Talk => 'Talk to',
        };
    }

    /**
     * Whether this verb may be combined with an inventory item.
     */
    public function acceptsItem(): bool
    {
        return $this === self::Use;
    }

    /**
     * The response shown when no interaction matches this verb.
     */
    public function fallbackResponse(): string
    {
        return match ($this) {
            self::Look => 'Nothing about it stands out.',
            self::Use => 'That does not work.',
            self::Take => 'You cannot take that.',
            self::Talk => 'It has nothing to say.',
        };
    }
}
