<?php

namespace App\Services;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils as StreamUtils;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

class GithubBridgeSource
{
    public function __construct(private PortalSettings $settings) {}

    public function resolve(string $repository, string $reference): string
    {
        $this->repository($repository);
        $response = $this->download('https://api.github.com/repos/'.$repository.'/commits/'.rawurlencode($reference), true, 262144, 'application/vnd.github.sha');
        $commit = trim($response->body());
        if (! $response->ok() || ! is_string($commit) || ! preg_match('/\A[0-9a-f]{40}\z/', $commit)) {
            $this->fail();
        }

        return $commit;
    }

    /** @return array{bytes: string, source_sha256: string} */
    public function bundle(string $repository, string $commit): array
    {
        $this->repository($repository);
        if (! preg_match('/\A[0-9a-f]{40}\z/', $commit)) {
            $this->fail();
        }
        $response = $this->download('https://api.github.com/repos/'.$repository.'/zipball/'.$commit, true, 8388608);
        if ($response->status() === 302) {
            $location = $response->header('Location');
            $parts = parse_url($location);
            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'codeload.github.com'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
                || ! str_starts_with(strtolower($parts['path'] ?? ''), '/'.strtolower($repository).'/')) {
                $this->fail();
            }
            $response = $this->download($location, false, 8388608);
        }
        if (! $response->ok()) {
            $this->fail();
        }
        $path = $this->temporaryFile();
        $archive = new ZipArchive;
        $opened = false;
        try {
            File::put($path, $response->body());
            $opened = $archive->open($path) === true;
            if (! $opened || $archive->numFiles > 10000) {
                $this->fail();
            }
            $files = [];
            $seen = [];
            $prefix = null;
            $total = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $name = $entry['name'];
                if (! $this->safePath(rtrim($name, '/')) || isset($seen[$name])) {
                    $this->fail();
                }
                $seen[$name] = true;
                $parts = explode('/', $name, 2);
                $prefix ??= $parts[0];
                $archive->getExternalAttributesIndex($index, $operatingSystem, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if ($prefix !== $parts[0] || ! in_array($type, [0, 0100000, 0040000], true)) {
                    $this->fail();
                }
                $relative = $parts[1] ?? '';
                if (str_ends_with($name, '/') || ! $this->allowedSource($relative)) {
                    continue;
                }
                $total += $entry['size'];
                if ($entry['size'] > 2097152 || $total > 16777216) {
                    $this->fail();
                }
                $raw = $archive->getFromIndex($index);
                if (! is_string($raw) || strlen($raw) !== $entry['size']) {
                    $this->fail();
                }
                $files[$relative] = $raw;
            }
            foreach (['init.lua', 'bridge.lua', 'mod.conf', 'api/serve.py', 'api/development_proxy.py', 'api/requirements.txt', 'lifecycle/install_docker.py', 'tools/deploy_development.py'] as $required) {
                if (! isset($files[$required])) {
                    throw ValidationException::withMessages(['bridge' => 'Dieser GitHub-Commit enthält noch keinen vollständigen Docker-Installer. Bitte die Bridge-Änderungen zuerst pushen.']);
                }
            }
            ksort($files);
            $hashes = array_map(fn (string $raw): string => hash('sha256', $raw), $files);
            $digest = hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $files['bundle.json'] = json_encode(['version' => 1, 'files' => $hashes, 'sha256' => $digest, 'git_head' => $commit, 'git_dirty' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            return ['bytes' => $this->zip($files), 'source_sha256' => $digest];
        } finally {
            if ($opened) {
                $archive->close();
            }
            File::delete($path);
        }
    }

    public function game(string $directory): string
    {
        $root = realpath($directory);
        if ($root === false || is_link($directory) || ! is_file($root.'/game.conf')) {
            throw ValidationException::withMessages(['bridge' => 'Bitte ein vorhandenes Minetest-Game-Verzeichnis auf dem Portal-Host konfigurieren.']);
        }
        $iterator = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            fn ($file): bool => ! str_starts_with($file->getFilename(), '.'));
        $files = [];
        $size = 0;
        foreach (new RecursiveIteratorIterator($iterator) as $file) {
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $info = lstat($file->getPathname());
            if ($file->isLink() || ! $file->isFile() || ($info['nlink'] ?? 0) !== 1 || ! $this->safePath($name)
                || array_intersect(explode('/', $name), ['secrets', 'world', 'worlds', 'inbox', 'outbox', 'receipts']) !== []
                || preg_match('/(?:^|\/)(?:AGENTS\.md|server\.conf|state\.json|token\.(?:json|lock)|(?:id_rsa|id_ed25519|id_ecdsa|gitleaks-report).*|.*\.(?:pem|key|token|log)|world\.mt|.*\.sqlite.*|.*\.db(?:-.*)?)$/i', $name)) {
                throw ValidationException::withMessages(['bridge' => 'Das Spielverzeichnis enthält unzulässige Dateien oder Verknüpfungen.']);
            }
            $size += $file->getSize();
            if ($file->getSize() > 16777216 || $size > 134217728 || count($files) >= 10000) {
                $this->fail();
            }
            $files[$name] = File::get($file->getPathname());
        }
        ksort($files);

        return $this->zip($files);
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = $this->temporaryFile();
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                $this->fail();
            }
            foreach ($files as $name => $raw) {
                $zip->addFromString($name, $raw);
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
                $zip->setMtimeName($name, 1767225600);
            }
            $zip->close();
            if (filesize($path) > 67108864) {
                $this->fail();
            }

            return File::get($path);
        } finally {
            File::delete($path);
        }
    }

    private function temporaryFile(): string
    {
        File::ensureDirectoryExists(storage_path('app/private/bridge-distribution'), 0700);
        $path = tempnam(storage_path('app/private/bridge-distribution'), 'source-');
        if ($path === false) {
            $this->fail();
        }
        chmod($path, 0600);

        return $path;
    }

    private function safePath(string $name): bool
    {
        return $name !== '' && ! preg_match('/[\\\\\x00-\x1f:]/', $name) && ! str_starts_with($name, '/')
            && ! in_array('..', explode('/', $name), true) && ! in_array('.', explode('/', $name), true) && ! str_contains($name, '//');
    }

    private function allowedSource(string $name): bool
    {
        return in_array($name, ['init.lua', 'bridge.lua', 'mod.conf', 'LICENSE', 'README.md'], true)
            || (bool) preg_match('/\A(?:api|lifecycle|tools)\/[A-Za-z0-9_]+\.py\z/', $name) || $name === 'api/requirements.txt';
    }

    private function repository(string $repository): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9_.-]{1,100}\z/', $repository)
            || in_array(explode('/', $repository)[1], ['.', '..'], true)) {
            $this->fail();
        }
    }

    private function download(string $url, bool $authenticated, int $limit, string $accept = 'application/vnd.github+json'): Response
    {
        $sink = new BoundedBridgeResponseStream($limit);
        try {
            $handler = $this->transportHandler();
            $client = Http::accept($accept)->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Luanti-Portal'])
                ->connectTimeout(5)->timeout(60)->withoutRedirecting()
                ->setHandler(function (RequestInterface $request, array $options) use ($handler, $sink): PromiseInterface {
                    $options['sink'] = $sink;

                    return $handler($request, $options);
                });
            if ($authenticated && filled($this->settings->githubToken())) {
                $client->withToken($this->settings->githubToken());
            }
            $response = $client->get($url);
            if ($sink->exceededLimit() || strlen($response->body()) > $limit) {
                $this->fail();
            }

            return new Response($response->toPsrResponse()->withBody(StreamUtils::streamFor($response->body())));
        } catch (Throwable) {
            $this->fail();
        } finally {
            $sink->close();
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['bridge' => 'GitHub-Download nicht bestätigt. Bitte Repository, Branch, Token (Contents: read) und Netzwerk prüfen.']);
    }

    protected function transportHandler(): callable
    {
        return Utils::chooseHandler();
    }
}
