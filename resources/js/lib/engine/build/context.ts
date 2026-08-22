import { createMaterialLibrary } from '@/lib/engine/build/materials';
import type { MaterialLibrary } from '@/lib/engine/build/materials';
import { createScene } from '@/lib/engine/build/scene';
import type { LevelScene } from '@/lib/engine/build/scene';
import { readTopology } from '@/lib/engine/build/topology';
import type { Topology } from '@/lib/engine/build/topology';
import type { TextureLibrary } from '@/lib/engine/textures';
import type { Level } from '@/types';

/**
 * Everything a builder needs and nothing it does not: the level being read, the
 * textures to draw it with, the materials made so far, what the floor plan says
 * about itself, and the scene being filled in.
 *
 * One context is threaded through the whole build, so a wall and the pane that
 * replaces it share the same caches and the same disposal.
 */
export type BuildContext = {
    level: Level;
    textures: TextureLibrary;
    scene: LevelScene;
    materials: MaterialLibrary;
    topology: Topology;
};

export function createBuildContext(
    level: Level,
    textures: TextureLibrary,
): BuildContext {
    return {
        level,
        textures,
        scene: createScene(),
        materials: createMaterialLibrary(level, textures),
        topology: readTopology(level),
    };
}
