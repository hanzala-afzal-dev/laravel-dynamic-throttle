<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Afzal\DynamicThrottle\Dashboard\Links\LinkToken;
use Afzal\DynamicThrottle\Dashboard\Links\SessionLink;
use Afzal\DynamicThrottle\Models\DashboardLink;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POST {path}/api/link, what the link's page posts when its holder presses "Continue"
 * (SPEC-034): uses the link the token names, once, then starts the session it opens.
 * The token comes in the body, never the address, so no server log or Referer header
 * holds it. It needs no gate: the link is what lets its holder in.
 *
 * @internal
 */
final readonly class LinkController
{
    /** One message for every link that cannot be used, whatever the reason (decision 15). */
    public const string NOT_VALID = 'This link is not valid: it was already used, it was revoked, or it has expired. Ask for a new one.';

    public function __construct(
        private EmergencyControls $controls,
    ) {}

    public function __invoke(Request $request): Response
    {
        // From the body only, never the query: a token in an address ends up in server logs (decision 5).
        $token = Validator::make(['token' => $request->post('token')], ['token' => ['required', 'string', 'regex:'.LinkToken::FORMAT]])->validate()['token'];

        // dashboard.middleware without the web group: no session to start, and the health check says so.
        if (! $request->hasSession()) {
            throw new HttpException(500, 'Links need a session: add the web group to dynamic-throttle.dashboard.middleware.');
        }

        $link = $this->controls->useLink(LinkToken::hash($token), $request->ip(), $request->userAgent());

        if (! $link instanceof DashboardLink) {
            throw new AccessDeniedHttpException(self::NOT_VALID);
        }

        // A new id, so a session id anyone saw before the link was used opens nothing (decision 9).
        $request->session()->regenerate();
        $request->session()->put(SessionLink::KEY, $link->id);

        return new Response(status: 204);
    }
}
