<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Explanation;

use Afzal\DynamicThrottle\Enums\RequestMethod;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The request dynamic-throttle:explain describes (SPEC-014): what the scopes read
 * (ADR-0009). The JSON API (SPEC-017) and the dashboard (SPEC-026) take the same.
 *
 * @internal
 */
final readonly class ExplanationRequest
{
    /**
     * @param  string  $method  in capitals, one a rule can name
     * @param  string  $path  as given: a leading slash and a query string are ignored, as the middleware ignores them
     * @param  string  $ip  the client's IP as the app sees it, after its trusted proxies
     * @param  ?string  $user  a user's id, looked up by the user provider of the guard
     * @param  ?string  $guard  the guard that looks the user up, or null for the default guard
     * @param  list<array{string, string}>  $headers  each header's name and value, in order
     */
    private function __construct(
        public string $method,
        public string $path,
        public string $ip,
        public ?string $user,
        public ?string $guard,
        public array $headers,
    ) {}

    /**
     * @param  list<string>  $headers  each as "Name: value"
     *
     * @throws InvalidArgumentException naming the input that is wrong
     */
    public static function of(string $method, string $path, string $ip = '127.0.0.1', ?string $user = null, ?string $guard = null, array $headers = []): self
    {
        $upper = strtoupper($method);

        if ($upper === RequestMethod::Any->value || RequestMethod::tryFrom($upper) === null) {
            throw new InvalidArgumentException('The method must be GET, HEAD, POST, PUT, PATCH, DELETE or OPTIONS, got "'.$method.'".');
        }

        if ($guard !== null && $user === null) {
            throw new InvalidArgumentException('--guard needs --user: it names the guard that looks the user up.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('The IP must be an IPv4 or IPv6 address, got "'.$ip.'".');
        }

        return new self($upper, $path, $ip, $user, $guard, array_map(self::header(...), $headers));
    }

    /**
     * The request as Laravel builds it, from the client's IP.
     */
    public function toRequest(): Request
    {
        $request = Request::create('/'.ltrim($this->path, '/'), $this->method, server: ['REMOTE_ADDR' => $this->ip]);

        foreach ($this->headers as [$name, $value]) {
            $request->headers->set($name, $value, replace: false);
        }

        return $request;
    }

    /**
     * The headers' names, without their values (decision 4).
     *
     * @return list<string>
     */
    public function headerNames(): array
    {
        return array_map(fn (array $header): string => $header[0], $this->headers);
    }

    /**
     * @return array{string, string}
     *
     * @throws InvalidArgumentException
     */
    private static function header(string $header): array
    {
        $parts = explode(':', $header, 2);
        $name = trim($parts[0]);

        if (count($parts) !== 2 || $name === '') {
            throw new InvalidArgumentException('A header must be a name, a colon and a value, such as "X-Api-Key: abc", got "'.$header.'".');
        }

        return [$name, trim($parts[1])];
    }
}
