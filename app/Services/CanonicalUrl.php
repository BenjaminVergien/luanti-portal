<?php

namespace App\Services;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

class CanonicalUrl
{
    public function __construct(private Application $app) {}

    public function origin(): string
    {
        $url = $this->app->make('config')->get('app.url');
        $parts = is_string($url) ? parse_url($url) : false;
        $scheme = strtolower($parts['scheme'] ?? '');

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('APP_URL must be an absolute HTTP(S) URL with a hostname and without credentials, query, or fragment.');
        }

        if ($this->app->environment('production') && $scheme !== 'https') {
            throw new InvalidArgumentException('APP_URL must use HTTPS in production.');
        }

        return $scheme.substr($url, strlen($parts['scheme']));
    }
}
