<?php

namespace Database\Seeders;

use App\Enums\ConditionType;
use App\Enums\EffectType;
use App\Enums\Verb;
use Database\Seeders\Concerns\AuthorsGames;
use Illuminate\Database\Seeder;

/**
 * Game one. A two room burglary that exercises every condition and effect type.
 */
class TheStudySeeder extends Seeder
{
    use AuthorsGames;

    public function run(): void
    {
        $game = $this->game(
            'the-study',
            'The Study',
            'You have one night, someone else\'s house, and a journal to find.',
        );

        $study = $this->scene($game, 'study', 'The Study', 'Rain runs down the window. The lamp on the desk is the only light in the house.', '#1c1a17');
        $hallway = $this->scene($game, 'hallway', 'The Hallway', 'A narrow hallway. The front door is at the far end, and it is still raining outside.', '#14161c');

        $game->update(['starting_scene_id' => $study->id]);

        $brassKey = $this->item($game, 'brass-key', 'Brass Key', 'A small brass key, green at the teeth.');
        $this->item($game, 'journal', 'Leather Journal', 'Water-stained, and written in a hand you recognise.');
        $umbrella = $this->item($game, 'umbrella', 'Umbrella', 'Black, sturdy, and someone else\'s.');

        $rug = $this->hotspot($study, 'rug', 'Threadbare Rug', x: 30, y: 68, width: 34, height: 22);
        $keyOnFloor = $this->hotspot($study, 'brass-key', 'Something Glinting', x: 40, y: 72, width: 8, height: 8, visible: false);
        $desk = $this->hotspot($study, 'desk', 'Oak Desk', x: 8, y: 42, width: 30, height: 30);
        $portrait = $this->hotspot($study, 'portrait', 'Portrait', x: 62, y: 14, width: 20, height: 28);
        $studyDoor = $this->hotspot($study, 'door', 'Study Door', x: 84, y: 30, width: 13, height: 55);

        $backDoor = $this->hotspot($hallway, 'study-door', 'Study Door', x: 4, y: 30, width: 13, height: 55);
        $stand = $this->hotspot($hallway, 'umbrella-stand', 'Umbrella Stand', x: 38, y: 60, width: 14, height: 28);
        $frontDoor = $this->hotspot($hallway, 'front-door', 'Front Door', x: 70, y: 22, width: 22, height: 64);

        $this->interaction($rug, Verb::Look, 'Threadbare, and rucked up at one corner as though someone left in a hurry.');
        $this->interaction($rug, Verb::Take, 'It is nailed down at the edges. It is staying where it is.');
        $this->interaction($rug, Verb::Use, 'The rug is already crumpled against the skirting board.',
            priority: 10,
            conditions: [[ConditionType::FlagIs, 'rug_moved', 'yes']],
        );
        $this->interaction($rug, Verb::Use, 'You drag the rug aside. Something small catches the lamplight.',
            effects: [
                [EffectType::SetFlag, 'rug_moved', 'yes'],
                [EffectType::RevealHotspot, 'brass-key'],
            ],
        );

        $this->interaction($keyOnFloor, Verb::Look, 'A brass key, lying flat against the boards.');
        $this->interaction($keyOnFloor, Verb::Take, 'You pocket the brass key.',
            effects: [
                [EffectType::GiveItem, 'brass-key'],
                [EffectType::HideHotspot, 'brass-key'],
            ],
        );

        $this->interaction($desk, Verb::Look, 'Oak, and older than the house. The single drawer has a brass lock.',
            priority: 10,
            conditions: [[ConditionType::FlagIsNot, 'drawer_open', 'yes']],
        );
        $this->interaction($desk, Verb::Look, 'The drawer hangs open. There is nothing left in it.');
        $this->interaction($desk, Verb::Use, 'The drawer is locked, and the lock is not the kind that gives.',
            priority: 10,
            conditions: [[ConditionType::FlagIsNot, 'drawer_open', 'yes']],
        );
        $this->interaction($desk, Verb::Use, 'You have already taken everything worth taking.');
        $this->interaction($desk, Verb::Use, 'The key turns. Inside the drawer, wrapped in oilcloth, is a leather journal. You leave the key in the lock.',
            item: $brassKey,
            effects: [
                [EffectType::SetFlag, 'drawer_open', 'yes'],
                [EffectType::GiveItem, 'journal'],
                [EffectType::RemoveItem, 'brass-key'],
            ],
        );

        $this->interaction($portrait, Verb::Look, 'A stern man in a black coat. The brass plate below him has been unscrewed and taken away.');
        $this->interaction($portrait, Verb::Talk, 'You ask him where it is. He has kept quiet for forty years and sees no reason to stop.');

        $this->interaction($studyDoor, Verb::Look, 'The door to the hallway. You left it ajar on the way in.');
        $this->interaction($studyDoor, Verb::Use, 'Not yet. You did not break into this house to leave empty-handed.',
            priority: 10,
            conditions: [[ConditionType::MissingItem, 'journal']],
        );
        $this->interaction($studyDoor, Verb::Use, 'You step out into the hallway.',
            effects: [[EffectType::MoveToScene, 'hallway']],
        );

        $this->interaction($backDoor, Verb::Look, 'The study, and the lamp you should probably have turned off.');
        $this->interaction($backDoor, Verb::Use, 'You go back into the study.',
            effects: [[EffectType::MoveToScene, 'study']],
        );

        $this->interaction($stand, Verb::Look, 'A ceramic stand holding one black umbrella.');
        $this->interaction($stand, Verb::Take, 'You take the umbrella. It is raining, after all.',
            effects: [[EffectType::GiveItem, 'umbrella']],
        );

        $this->interaction($frontDoor, Verb::Look, 'The front door. Rain is coming under it in a thin line.');
        $this->interaction($frontDoor, Verb::Use, 'You step out into the rain with the journal under your coat, and pull the door shut behind you.',
            conditions: [[ConditionType::HasItem, 'journal']],
            effects: [[EffectType::SetFlag, 'escaped', 'yes']],
        );
        $this->interaction($frontDoor, Verb::Use, 'You have not found what you came for.');
        $this->interaction($frontDoor, Verb::Use, 'You put the umbrella up. Inside. It does not help.',
            item: $umbrella,
        );
    }
}
