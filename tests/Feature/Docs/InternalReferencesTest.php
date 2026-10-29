<?php

declare(strict_types=1);

/*
| Issue #152: what users read names only what they have. Health messages show
| in the console and the dashboard, and the config file is published into the
| app: neither may point to a spec, an ADR or a file of this repository, which
| an app does not have. They link to the documentation site instead, and each
| of those links names a page and a heading that exist.
*/

function referencesRoot(): string
{
    return dirname(__DIR__, 3);
}

/** Specs, ADRs and paths of this repository's docs. */
const INTERNAL_REFERENCE = '~\b(?:SPEC|ADR)-\d+|docs/(?:guide|adr|specs|runbook)\b|\.md\b~';

/**
 * Every string in the package's PHP code, comments left out, with where it is.
 *
 * @return list<array{string, string}>
 */
function packageStrings(): array
{
    $strings = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(referencesRoot().'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $strings[] = [substr($file->getPathname(), strlen(referencesRoot()) + 1).':'.$token[2], $token[1]];
            }
        }
    }

    return $strings;
}

/** GitHub's heading ids, as the site makes them: lowercase, without punctuation, spaces as hyphens. */
function headingId(string $heading): string
{
    return str_replace(' ', '-', (string) preg_replace('~[^\p{L}\p{M}\p{N}\p{Pc} -]~u', '', mb_strtolower($heading)));
}

it('names no spec, ADR or repository path in a string of the package', function (): void {
    $found = array_values(array_filter(packageStrings(), fn (array $string): bool => preg_match(INTERNAL_REFERENCE, $string[1]) === 1));

    expect(array_map(fn (array $string): string => $string[0], $found))->toBe([]);
});

it('names no spec, ADR or repository path in the published config file', function (): void {
    preg_match_all(INTERNAL_REFERENCE, (string) file_get_contents(referencesRoot().'/config/dynamic-throttle.php'), $found);

    expect($found[0])->toBe([]);
});

it('links each message to a page and a heading of the documentation site that exist', function (): void {
    $source = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(referencesRoot().'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $source .= $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';
    }

    preg_match_all('~Docs::url\(\s*\'([^\'#]+)#([^\']+)\'\s*\)~', $source, $links, PREG_SET_ORDER);

    expect($links)->not->toBe([]);

    foreach ($links as [, $page, $id]) {
        $markdown = referencesRoot()."/docs/{$page}.md";

        expect(is_file($markdown))->toBeTrue("docs/{$page}.md does not exist");

        preg_match_all('~^#{1,6}\s+(.+)$~m', (string) file_get_contents($markdown), $headings);

        expect(array_map(headingId(...), $headings[1]))->toContain($id);
    }
});
