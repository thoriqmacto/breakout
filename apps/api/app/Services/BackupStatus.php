<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BackupStatus
{
    /**
     * @return array{generated_at:string,locations:array<int, array<string, mixed>>,collections:array<int, array<string, mixed>>}
     */
    public function report(string $remoteDisk = 'gdrive'): array
    {
        $remote = $this->disk($remoteDisk);
        $collections = [
            $this->collection(
                'historical',
                'Historical prices',
                $this->localDirectoryFiles((string) config('csv.seed_dir'), ['csv']),
                $this->diskFiles($remote, trim((string) config('csv.mirror_path', 'seeds/historical'), '/'), ['csv']),
            ),
            $this->collection(
                'broker_summary',
                'Broker summary',
                $this->diskFiles($this->disk('local'), trim((string) config('stockbit.save_dir', 'broker_summary'), '/'), ['csv', 'json']),
                $this->diskFiles($remote, trim((string) config('stockbit.save_dir', 'broker_summary'), '/'), ['csv', 'json']),
            ),
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'locations' => [
                ['key' => 'local', 'label' => 'Local', 'available' => true],
                ['key' => 'gdrive', 'label' => 'Google Drive', 'available' => $remote !== null],
            ],
            'collections' => $collections,
        ];
    }

    /** @return array<string, array{path:string,size:?int,modified_at:?string}> */
    private function localDirectoryFiles(string $directory, array $extensions): array
    {
        $files = [];
        foreach (glob(rtrim($directory, '/'.DIRECTORY_SEPARATOR).'/*') ?: [] as $path) {
            if (! is_file($path) || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
                continue;
            }
            $name = basename($path);
            $modified = filemtime($path);
            $size = filesize($path);
            $files[$name] = [
                'path' => $path,
                'size' => $size === false ? null : $size,
                'modified_at' => $modified === false ? null : date(DATE_ATOM, $modified),
            ];
        }

        return $files;
    }

    /** @return array<string, array{path:string,size:?int,modified_at:?string}> */
    private function diskFiles(?Filesystem $disk, string $directory, array $extensions): array
    {
        if ($disk === null) {
            return [];
        }

        try {
            $paths = $disk->allFiles($directory);
        } catch (Throwable) {
            return [];
        }

        $files = [];
        foreach ($paths as $path) {
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
                continue;
            }
            $name = ltrim(substr($path, strlen($directory)), '/');
            try {
                $timestamp = $disk->lastModified($path);
                $size = $disk->size($path);
            } catch (Throwable) {
                $timestamp = null;
                $size = null;
            }
            $files[$name] = [
                'path' => $path,
                'size' => $size,
                'modified_at' => $timestamp ? date(DATE_ATOM, $timestamp) : null,
            ];
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function collection(string $key, string $label, array $local, array $remote): array
    {
        $names = array_values(array_unique([...array_keys($local), ...array_keys($remote)]));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $files = array_map(function (string $name) use ($local, $remote): array {
            $localFile = $local[$name] ?? null;
            $remoteFile = $remote[$name] ?? null;
            $state = $localFile && $remoteFile ? 'synced' : ($localFile ? 'local_only' : 'gdrive_only');

            return ['name' => $name, 'state' => $state, 'local' => $localFile, 'gdrive' => $remoteFile];
        }, $names);

        return [
            'key' => $key,
            'label' => $label,
            'counts' => [
                'total' => count($files),
                'local' => count($local),
                'gdrive' => count($remote),
                'synced' => count(array_filter($files, fn (array $file) => $file['state'] === 'synced')),
            ],
            'files' => $files,
        ];
    }

    private function disk(string $name): ?Filesystem
    {
        try {
            return Storage::disk($name);
        } catch (Throwable) {
            return null;
        }
    }
}
