<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writing down what a spot looked like.
 *
 * A ticket and a debug snapshot want the same capture and differ only in where
 * it lands — one in rows to be listed and marked dealt with, the other in a
 * folder that can be deleted when the fault it was chasing is gone. This is the
 * part they share: the pictures, and the legend without which one of them means
 * nothing.
 *
 * **The legend is not an extra.** `paintWalls` hands out colours by walking the
 * scene graph with a running counter, so which colour is which wall belongs to
 * that build of that level and cannot be worked out from the pixels — `scanRow`
 * takes it as an argument for exactly that reason. A colour screen saved
 * without its legend is a file that looks like evidence and decodes to nothing,
 * so this writes them together or not at all.
 *
 * It is also the half a machine can read. The pictures are what a person looks
 * at; the legend is what another agent computes on without opening a browser.
 */
class SpotCapture
{
    /** Never the public disk. These are bytes from whoever was playing. */
    public const DISK = 'local';

    /** What the legend is called wherever a capture is a folder. */
    public const LEGEND_FILE = 'legend.json';

    /**
     * Writes a capture into one folder.
     *
     * @param  array<string, UploadedFile>  $shots  By the view each one is.
     * @param  array<int, mixed>|null  $legend  Colour to wall, for the walls view.
     * @return array<int, array{kind: string, path: string, bytes: int}>
     */
    public function write(string $folder, array $shots, ?array $legend = null): array
    {
        $written = [];

        foreach ($shots as $kind => $file) {
            // The extension comes from what the file actually is rather than
            // from what it was called, since anybody may post here.
            $path = $file->storeAs($folder, "{$kind}.".$file->extension(), self::DISK);

            // A disk that will not take the file gives back false. Storing that
            // as a path would leave a ticket pointing at nothing and looking
            // complete, so it fails here and whatever transaction is open rolls
            // the ticket back with it.
            if ($path === false) {
                throw new RuntimeException("Could not write {$kind} into {$folder}.");
            }

            $written[] = [
                'kind' => $kind,
                'path' => $path,
                'bytes' => $file->getSize() ?: 0,
            ];
        }

        if ($legend !== null) {
            Storage::disk(self::DISK)->put(
                $folder.'/'.self::LEGEND_FILE,
                json_encode($legend, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
            );
        }

        return $written;
    }

    /**
     * Takes a whole capture away again, folder and all.
     */
    public function forget(string $folder): void
    {
        Storage::disk(self::DISK)->deleteDirectory($folder);
    }
}
