<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class LuantiImageCatalog
{
    public const string Repository = 'ghcr.io/luanti-org/luanti';

    public const string CacheKey = 'luanti:docker-images:official:v1';

    public function __construct(private PortalAccess $access) {}

    /** @return array<string, string> */
    public function images(): array
    {
        return Cache::get(self::CacheKey, [])['images'] ?? [];
    }

    public function needsRefresh(): bool
    {
        return (Cache::get(self::CacheKey, [])['fetched_at'] ?? 0) < now()->subHours(6)->timestamp;
    }

    /** @return array<string, string> */
    public function refresh(User $actor): array
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('viewAny', LuantiServer::class);
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(6)->withoutRedirecting()
                ->get('https://ghcr.io/token', ['service' => 'ghcr.io', 'scope' => 'repository:luanti-org/luanti:pull']);
            $token = $response->json('token');
            if (! $response->ok() || ! is_string($token) || blank($token) || strlen($token) > 8192 || preg_match('/\s/', $token)) {
                $this->fail();
            }
            $tags = [];
            $last = null;
            for ($page = 0; $page < 5; $page++) {
                $query = ['n' => 100];
                if ($last !== null) {
                    $query['last'] = $last;
                }
                $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(6)->withoutRedirecting()
                    ->get('https://ghcr.io/v2/luanti-org/luanti/tags/list', $query);
                $batch = $response->json('tags');
                if (! $response->ok() || $response->json('name') !== 'luanti-org/luanti' || ! is_array($batch) || ! array_is_list($batch)) {
                    $this->fail();
                }
                foreach ($batch as $tag) {
                    if (! is_string($tag) || ! preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/', $tag)) {
                        $this->fail();
                    }
                    $tags[$tag] = $tag;
                }
                if (! preg_match('/\brel\s*=\s*"?next\b/i', $response->header('Link') ?? '')) {
                    break;
                }
                $next = $batch === [] ? null : $batch[array_key_last($batch)];
                if ($page === 4 || $next === null || $next === $last) {
                    $this->fail();
                }
                $last = $next;
            }
        } catch (ConnectionException) {
            $this->fail();
        }
        if ($tags === []) {
            $this->fail();
        }
        usort($tags, function (string $first, string $second): int {
            $firstIsVersion = preg_match('/\A\d+\.\d+\.\d+(?:[-.].*)?\z/', $first) === 1;
            $secondIsVersion = preg_match('/\A\d+\.\d+\.\d+(?:[-.].*)?\z/', $second) === 1;

            if ($firstIsVersion !== $secondIsVersion) {
                return $firstIsVersion ? -1 : 1;
            }

            return $firstIsVersion ? version_compare($second, $first) : strnatcmp($first, $second);
        });
        $images = [];
        foreach ($tags as $tag) {
            $images[self::Repository.':'.$tag] = match ($tag) {
                'latest' => 'latest (aktuelle stabile Version)', 'master' => 'master (Entwicklung)', default => $tag,
            };
        }
        Cache::forever(self::CacheKey, ['images' => $images, 'fetched_at' => now()->timestamp]);

        return $images;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages([
            'luanti_docker_image' => 'Die Image-Liste konnte nicht geladen werden. Die bisherige Liste und das gewählte Image bleiben erhalten.',
        ]);
    }
}
