<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Dashboard;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
| SPEC-020 AC-1 to AC-5: the page shell, which every page of the dashboard loads,
| its strict Content Security Policy with a fresh nonce, and the asset route. The
| fixture build in tests/Fixtures/dist names its files main-abc123.js,
| vendor-def456.js, rules-jkl012.js and main-ghi789.css.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    Dashboard::fixtureBuild();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * @return TestResponse<Response>
 */
function shell(string $path = '/dynamic-throttle'): TestResponse
{
    return test()->actingAs(DashboardApi::viewer())->get($path);
}

/**
 * The nonce in the shell's <meta> tag.
 *
 * @param  TestResponse<Response>  $response
 */
function metaNonce(TestResponse $response): ?string
{
    return preg_match('/<meta name="csp-nonce" content="([^"]+)">/', (string) $response->getContent(), $match) === 1 ? $match[1] : null;
}

/*
| AC-1: the shell answers every page's path.
*/

it('AC-1: answers the shell for the dashboard\'s root and for any page under it, to a viewer', function (string $path): void {
    $response = shell($path);

    expect([$response->status(), $response->headers->get('Content-Type')])->toBe([200, 'text/html; charset=utf-8'])
        ->and($response->getContent())->toContain('<div id="dynamic-throttle"');
})->with(['/dynamic-throttle', '/dynamic-throttle/rules', '/dynamic-throttle/rules/12', '/dynamic-throttle/audit']);

it('AC-1: refuses a guest in production, and leaves api/ paths to the API', function (): void {
    $guest = $this->get('/dynamic-throttle/rules');
    $api = $this->actingAs(DashboardApi::viewer())->getJson('/dynamic-throttle/api/nope');

    expect([$guest->status(), $api->status()])->toBe([403, 404]);
});

/*
| AC-2: what the shell holds, and nothing inline.
*/

it('AC-2: holds the root with the base path, the built assets, the CSRF token and the nonce', function (): void {
    $response = shell();
    $html = (string) $response->getContent();

    expect($html)->toContain('<div id="dynamic-throttle" data-base="/dynamic-throttle"></div>')
        ->toContain('<link rel="stylesheet" href="/dynamic-throttle/assets/main-ghi789.css">')
        ->toContain('<link rel="modulepreload" href="/dynamic-throttle/assets/vendor-def456.js">')
        ->toContain('<script type="module" src="/dynamic-throttle/assets/main-abc123.js"></script>')
        ->toContain('<meta name="csrf-token" content="'.csrf_token().'">')
        ->and(metaNonce($response))->toMatch('/\A[A-Za-z0-9+\/]{22}==\z/');
});

it('AC-2: has no inline script body, no style attribute and no event handler attribute', function (): void {
    $html = (string) shell()->getContent();

    preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $scripts);

    expect(array_filter($scripts[1], fn (string $body): bool => trim($body) !== ''))->toBe([])
        ->and($html)->not->toMatch('/\sstyle\s*=/i')
        ->and($html)->not->toMatch('/\son[a-z]+\s*=/i')
        ->and($html)->not->toMatch('/<style\b/i');
});

it('AC-2: follows a configured path', function (): void {
    Dashboard::boot(['path' => 'ops/throttle']);
    Dashboard::fixtureBuild();

    $html = (string) shell('/ops/throttle/rules')->getContent();

    expect($html)->toContain('data-base="/ops/throttle"')
        ->toContain('<script type="module" src="/ops/throttle/assets/main-abc123.js"></script>');
});

/*
| AC-3: the Content Security Policy.
*/

it('AC-3: sends the strict policy with the nonce of the <meta> tag, new on every request', function (): void {
    $first = shell();
    $second = shell();
    $nonce = metaNonce($first);

    expect($first->headers->get('Content-Security-Policy'))->toBe(
        "default-src 'none'; script-src 'self'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data:; font-src 'self'; "
        ."connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
    )
        ->and($first->headers->get('Referrer-Policy'))->toBe('same-origin')
        ->and(metaNonce($second))->not->toBe($nonce);
});

it('AC-3: keeps a policy the app gave the response', function (): void {
    Route::aliasMiddleware('app-csp', fn (Request $request, Closure $next): Response => tap($next($request), function (Response $response): void {
        $response->headers->set('Content-Security-Policy', "default-src 'self'");
    }));
    Dashboard::boot(['middleware' => ['web', 'app-csp']]);
    Dashboard::fixtureBuild();

    expect(shell()->headers->get('Content-Security-Policy'))->toBe("default-src 'self'");
});

/*
| AC-4: the assets.
*/

it('AC-4: serves a built file to anyone, cached for a year, with its type and no cookie', function (string $file, string $type): void {
    $response = $this->get("/dynamic-throttle/assets/{$file}");

    expect($response->status())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toStartWith($type)
        ->and([$response->headers->hasCacheControlDirective('public'), $response->headers->getCacheControlDirective('max-age'), $response->headers->hasCacheControlDirective('immutable')])
        ->toBe([true, '31536000', true])
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->streamedContent() ?: $response->getContent())->toBe(file_get_contents(dirname(__DIR__, 2)."/Fixtures/dist/{$file}"));
})->with([
    'the entry' => ['main-abc123.js', 'application/javascript'],
    'an import' => ['vendor-def456.js', 'application/javascript'],
    'a page loaded on demand' => ['rules-jkl012.js', 'application/javascript'],
    'the stylesheet' => ['main-ghi789.css', 'text/css'],
]);

it('AC-4: answers 404 for a file the manifest does not list', function (string $file): void {
    expect($this->get("/dynamic-throttle/assets/{$file}")->status())->toBe(404);
})->with([
    'a file in dist/ but not in the manifest' => ['stray.js'],
    'no such file' => ['missing-zzz.js'],
    'the manifest' => ['manifest.json'],
    'the manifest by its path' => ['.vite%2Fmanifest.json'],
    'a way out of dist/' => ['..%2F..%2Fcomposer.json'],
]);

/*
| AC-5: no build.
*/

it('AC-5: answers 503 with the way to build when there is no build', function (): void {
    Dashboard::noBuild();

    $response = shell();

    expect($response->status())->toBe(503)
        ->and($response->getContent())->toContain('The dashboard is not built: run npm ci and npm run build.');
});
