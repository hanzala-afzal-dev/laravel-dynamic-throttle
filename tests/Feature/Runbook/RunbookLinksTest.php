<?php

declare(strict_types=1);

/*
| SPEC-029 AC-6: each scenario of docs/runbook.md links the test that follows
| its steps, and the README and the guide link the runbook. Links are read as
| GitHub reads them: relative to the file, with anchors made from headings.
*/

/**
 * A file of the repository, by its path from the root.
 */
function repositoryPath(string $path): string
{
    return dirname(__DIR__, 3).'/'.$path;
}

/**
 * The relative links of a Markdown file, as paths from the repository's root,
 * and the anchors of its links within the page.
 *
 * @return array{list<string>, list<string>}
 */
function markdownLinks(string $file, ?string $markdown = null): array
{
    $markdown ??= (string) file_get_contents(repositoryPath($file));
    preg_match_all('/\]\(([^)\s]+)\)/', $markdown, $matches);
    $paths = [];
    $anchors = [];

    foreach ($matches[1] as $link) {
        if (str_contains($link, '://')) {
            continue;
        }

        if (str_starts_with($link, '#')) {
            $anchors[] = substr($link, 1);

            continue;
        }

        $resolved = realpath(dirname(repositoryPath($file)).'/'.explode('#', $link)[0]);
        $paths[] = $resolved === false ? "missing: {$link}" : substr($resolved, strlen(repositoryPath('')));
    }

    return [$paths, $anchors];
}

/**
 * The runbook's scenarios, by heading, each with its own Markdown.
 *
 * @return array<string, string>
 */
function runbookSections(): array
{
    $sections = [];

    foreach (array_slice(explode("\n## ", (string) file_get_contents(repositoryPath('docs/runbook.md'))), 1) as $section) {
        [$heading, $body] = explode("\n", $section, 2);
        $sections[$heading] = $body;
    }

    return $sections;
}

it('AC-6: links, from each scenario, the test that follows its steps', function (): void {
    $tests = array_map(fn (string $body): array => array_values(array_filter(
        markdownLinks('docs/runbook.md', $body)[0],
        fn (string $path): bool => str_starts_with($path, 'tests/Feature/Runbook/'),
    )), runbookSections());

    expect($tests)->toBe([
        'Before you need it' => [],
        'Redis is down' => ['tests/Feature/Runbook/RedisDownTest.php'],
        'The manifest was evicted' => ['tests/Feature/Runbook/ManifestEvictedTest.php'],
        'A bad rule blocks /api' => ['tests/Feature/Runbook/BadRuleTest.php'],
        'An emergency change was left on' => ['tests/Feature/Runbook/EmergencyLeftOnTest.php'],
    ]);
});

it('AC-6: has no link to a file that does not exist, nor to a heading it does not have', function (): void {
    [$paths, $anchors] = markdownLinks('docs/runbook.md');
    // GitHub's anchors: lowercase, without punctuation, spaces as hyphens.
    $headings = array_map(
        fn (string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^a-z0-9 -]/', '', strtolower($heading))),
        array_keys(runbookSections()),
    );

    expect(array_filter($paths, fn (string $path): bool => str_starts_with($path, 'missing: ')))->toBe([])
        ->and(array_values(array_diff($anchors, $headings)))->toBe([]);
});

it('AC-6: is linked from the README and from the guide', function (string $file): void {
    expect(markdownLinks($file)[0])->toContain('docs/runbook.md');
})->with(['README.md', 'docs/guide/README.md']);
