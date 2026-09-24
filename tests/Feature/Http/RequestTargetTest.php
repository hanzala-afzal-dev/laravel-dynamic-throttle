<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Http\RequestTarget;
use Illuminate\Http\Request;

/*
| SPEC-007 AC-1: the method and path a request is matched on, taken the way
| Laravel's router takes them, so an encoded path or a form's _method cannot
| avoid a rule that the route itself does not avoid.
*/

it('AC-1: takes the URL-decoded path without slashes at either end, as the router matches it', function (): void {
    $target = RequestTarget::fromRequest(Request::create('/api/%75sers/', 'GET'));

    expect([$target->method, $target->path])->toBe(['GET', 'api/users']);
});

it('AC-1: takes / for the root', function (): void {
    $target = RequestTarget::fromRequest(Request::create('/', 'GET'));

    expect([$target->method, $target->path])->toBe(['GET', '/']);
});

it('AC-1: takes the method a form overrides with _method, as the router does', function (): void {
    // Laravel's HTTP kernel turns this on for every request it handles.
    Request::enableHttpMethodParameterOverride();

    $target = RequestTarget::fromRequest(Request::create('/api/users', 'POST', ['_method' => 'DELETE']));

    expect($target->method)->toBe('DELETE');
});
