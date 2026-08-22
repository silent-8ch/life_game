<?php

namespace App\Filament\Resources\Levels\Pages;

use App\Filament\Resources\Levels\LevelResource;
use App\Models\Level;
use App\Services\LevelStarter;
use Filament\Resources\Pages\CreateRecord;

class CreateLevel extends CreateRecord
{
    protected static string $resource = LevelResource::class;

    /**
     * A new level gets one room around the player's starting spot, so the map
     * editor opens on something walkable instead of an empty grid.
     */
    protected function afterCreate(): void
    {
        /** @var Level $level */
        $level = $this->record;

        app(LevelStarter::class)->room($level);
    }
}
