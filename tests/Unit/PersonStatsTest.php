<?php

use App\Services\LevelAssets;
use App\Services\PersonStats;

/**
 * The invariants, not anybody's particular numbers: redistributing a block is
 * meant to be a one-file edit that stays green.
 */
it('has a starting block for everyone a level can place', function (): void {
    expect(array_keys(PersonStats::STARTING))
        ->toBe(array_keys(LevelAssets::HEIGHTS));
});

it('gives every block the seven attributes, in order', function (): void {
    foreach (PersonStats::STARTING as $sprite => $block) {
        expect(array_keys($block))->toBe(PersonStats::ATTRIBUTES, "{$sprite}'s block");
    }
});

it('keeps every attribute inside the range', function (): void {
    foreach (PersonStats::STARTING as $sprite => $block) {
        foreach ($block as $attribute => $value) {
            expect($value)
                ->toBeGreaterThanOrEqual(PersonStats::MINIMUM, "{$sprite}'s {$attribute}")
                ->toBeLessThanOrEqual(PersonStats::MAXIMUM, "{$sprite}'s {$attribute}");
        }
    }
});

it('spends the same pool on everyone, so nobody starts ahead', function (): void {
    foreach (PersonStats::STARTING as $sprite => $block) {
        expect(array_sum($block))->toBe(PersonStats::BUDGET, "{$sprite}'s spend");
    }
});

it('starts a stranger neutral rather than throwing', function (): void {
    $stats = new PersonStats;

    expect($stats->for('nobody-in-particular'))->toBe($stats->neutral())
        ->and($stats->for(null))->toBe($stats->neutral())
        ->and(array_keys($stats->neutral()))->toBe(PersonStats::ATTRIBUTES)
        ->and(array_sum($stats->neutral()))->toBe(PersonStats::BUDGET);
});

it('hands back the block written down for a person', function (): void {
    expect((new PersonStats)->for('luna'))->toBe(PersonStats::STARTING['luna']);
});
