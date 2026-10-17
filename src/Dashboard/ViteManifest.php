<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

/**
 * The dashboard's build, as Vite's manifest describes it (ADR-0028, SPEC-020): the
 * entry the page shell loads, and every file the asset route may serve. Read once
 * per process; a deploy that ships a new build starts new processes.
 *
 * @internal
 */
final class ViteManifest
{
    /** @var array<string, array<array-key, mixed>>|null */
    private ?array $chunks = null;

    /**
     * @param  string  $directory  the build, such as the package's dist/
     */
    public function __construct(
        private readonly string $directory,
    ) {}

    /**
     * The entry's script, its stylesheets, and the scripts it imports, to preload; null
     * without a build.
     *
     * @return array{script: string, styles: list<string>, preloads: list<string>}|null
     */
    public function entry(): ?array
    {
        foreach ($this->chunks() as $key => $chunk) {
            if (($chunk['isEntry'] ?? false) === true && is_string($chunk['file'] ?? null)) {
                return [
                    'script' => $chunk['file'],
                    'styles' => $this->strings($chunk['css'] ?? []),
                    'preloads' => $this->imports($key, []),
                ];
            }
        }

        return null;
    }

    /**
     * Whether the manifest lists the file, so the asset route may serve it: a chunk, a
     * stylesheet or an asset. Nothing else in the directory, the manifest included.
     */
    public function serves(string $file): bool
    {
        foreach ($this->chunks() as $chunk) {
            if (($chunk['file'] ?? null) === $file
                || in_array($file, $this->strings($chunk['css'] ?? []), true)
                || in_array($file, $this->strings($chunk['assets'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    public function path(string $file): string
    {
        return $this->directory.'/'.$file;
    }

    /**
     * The files a chunk imports statically, and theirs, once each.
     *
     * @param  list<string>  $seen
     * @return list<string>
     */
    private function imports(string $key, array $seen): array
    {
        $files = [];

        foreach ($this->strings($this->chunks()[$key]['imports'] ?? []) as $import) {
            $file = $this->chunks()[$import]['file'] ?? null;

            if (in_array($import, $seen, true) || ! is_string($file)) {
                continue;
            }

            $seen[] = $import;
            $files = [...$files, $file, ...$this->imports($import, $seen)];
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    private function chunks(): array
    {
        if ($this->chunks !== null) {
            return $this->chunks;
        }

        $path = $this->directory.'/.vite/manifest.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $chunks = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $chunk) {
            if (is_string($key) && is_array($chunk)) {
                $chunks[$key] = $chunk;
            }
        }

        return $this->chunks = $chunks;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
