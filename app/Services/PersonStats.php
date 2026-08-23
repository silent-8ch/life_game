<?php

namespace App\Services;

/**
 * What a person is made of, before anything happens to them.
 *
 * Shaped like LevelAssets on purpose: one class, one table of numbers, and the
 * only place a starting stat block is written down. Nothing reads these yet —
 * they are defined, shipped to the client and tunable, and what they do is a
 * question for later.
 */
class PersonStats
{
    /** The seven, in canonical SPECIAL order. */
    public const ATTRIBUTES = [
        'strength',
        'perception',
        'endurance',
        'charisma',
        'intelligence',
        'agility',
        'luck',
    ];

    public const MINIMUM = 1;

    public const MAXIMUM = 10;

    /** Every person spends the same pool, so nobody starts ahead. */
    public const BUDGET = 35;   // 7 × 5

    /**
     * Where the six start.
     *
     * These are real people, so nobody here has been characterised on their
     * behalf: everyone begins flat, which is a valid spend of BUDGET and says
     * nothing about anyone. Redistributing them is the point of this file —
     * the tests hold the invariants (seven keys, each 1..10, summing to
     * BUDGET) rather than any particular person's numbers, so retuning is a
     * one-file edit that stays green.
     *
     * Height is not here: it lives in LevelAssets::HEIGHTS because it is
     * measured, where these are authored.
     *
     * @var array<string, array<string, int>>
     */
    public const STARTING = [
        'paul' => [
            'strength' => 5,
            'perception' => 5,
            'endurance' => 5,
            'charisma' => 5,
            'intelligence' => 5,
            'agility' => 5,
            'luck' => 5,
        ],
        'paul-toon' => [
            'strength' => 5,
            'perception' => 5,
            'endurance' => 5,
            'charisma' => 5,
            'intelligence' => 5,
            'agility' => 5,
            'luck' => 5,
        ],
        'wade' => [
            'strength' => 5,
            'perception' => 5,
            'endurance' => 5,
            'charisma' => 5,
            'intelligence' => 5,
            'agility' => 5,
            'luck' => 5,
        ],
        'krystal' => [
            'strength' => 5,
            'perception' => 5,
            'endurance' => 5,
            'charisma' => 5,
            'intelligence' => 5,
            'agility' => 5,
            'luck' => 5,
        ],
        'krystal-toon' => [
            'strength' => 5,
            'perception' => 5,
            'endurance' => 5,
            'charisma' => 5,
            'intelligence' => 5,
            'agility' => 5,
            'luck' => 5,
        ],
        'luna' => [
            'strength' => 5,
            'perception' => 5,
            'endurance' => 5,
            'charisma' => 5,
            'intelligence' => 5,
            'agility' => 5,
            'luck' => 5,
        ],
        'luke' => [
            'strength' => 5,
            'perception' => 5,
            'endurance' => 5,
            'charisma' => 5,
            'intelligence' => 5,
            'agility' => 5,
            'luck' => 5,
        ],
        'william' => [
            'strength' => 5,
            'perception' => 5,
            'endurance' => 5,
            'charisma' => 5,
            'intelligence' => 5,
            'agility' => 5,
            'luck' => 5,
        ],
    ];

    /**
     * Where a person starts. Somebody nobody has written a block for — an
     * authored stranger, a sprite that has just turned up in the folder —
     * starts neutral rather than blowing up.
     *
     * @return array<string, int>
     */
    public function for(?string $sprite): array
    {
        if ($sprite === null) {
            return $this->neutral();
        }

        return self::STARTING[$sprite] ?? $this->neutral();
    }

    /**
     * The middle of the road: every attribute at the average of the range.
     *
     * @return array<string, int>
     */
    public function neutral(): array
    {
        return array_fill_keys(
            self::ATTRIBUTES,
            intdiv(self::BUDGET, count(self::ATTRIBUTES)),
        );
    }
}
