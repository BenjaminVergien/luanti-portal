<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PDO;
use Throwable;
use ZipArchive;

class PortalWorldTemplates
{
    public const int MAX_ARCHIVE_BYTES = 67108864;

    private const array CONTENT_FILES = ['world.mt', 'map.sqlite', 'map_meta.txt', 'env_meta.txt', 'mod_storage.sqlite'];

    public function __construct(private PortalAccess $access) {}

    public function import(User $actor, string $archivePath): LuantiWorldTemplate
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('create', LuantiWorldTemplate::class);
        $manifest = $this->inspect($archivePath);
        $digest = hash_file('sha256', $archivePath);
        File::ensureDirectoryExists($this->directory(), 0700);

        return Cache::lock('luanti:template-library', 120)->block(15, function () use ($actor, $archivePath, $manifest, $digest): LuantiWorldTemplate {
            Gate::forUser($this->access->currentUser($actor))->authorize('create', LuantiWorldTemplate::class);
            $path = $this->archivePath($digest);
            if (is_link($path)) {
                $this->fail();
            }
            if (! is_file($path)) {
                File::replace($path, File::get($archivePath), 0600);
            }
            $this->artifact($digest);

            return DB::transaction(function () use ($manifest, $digest): LuantiWorldTemplate {
                $template = LuantiWorldTemplate::query()->firstOrNew(['library_identifier' => $manifest['template_id']]);
                if (! $template->exists) {
                    $template->fill(['name' => $manifest['name'], 'template_identifier' => $manifest['template_id'], 'is_selectable' => true]);
                }
                $template->forceFill(['library_identifier' => $manifest['template_id'], 'luanti_server_id' => null,
                    'revision' => $manifest['revision'], 'bundle_sha256' => $digest, 'is_available' => true])->save();
                LuantiWorldTemplate::query()->whereNotNull('luanti_server_id')
                    ->where('template_identifier', $manifest['template_id'])->where('revision', $manifest['revision'])
                    ->update(['bundle_sha256' => $digest]);

                return $template;
            });
        });
    }

    /** @return list<array{template_id: string, name: string, revision: string, bundle_sha256: string, is_selectable: bool}> */
    public function snapshot(): array
    {
        $result = [];
        $bytes = 0;
        $expandedBytes = 0;
        foreach (LuantiWorldTemplate::query()->whereNotNull('library_identifier')->orderBy('library_identifier')->get() as $template) {
            $path = $this->artifact($template->bundle_sha256);
            $bytes += filesize($path);
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                $this->fail();
            }
            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $expandedBytes += $zip->statIndex($index)['size'];
                }
            } finally {
                $zip->close();
            }
            if ($bytes > self::MAX_ARCHIVE_BYTES || $expandedBytes > 134217728 || count($result) >= 1000) {
                throw ValidationException::withMessages(['bundle' => 'Die Vorlagenbibliothek überschreitet die Bereitstellungsgrenze von 64 MiB ZIP-Daten oder 128 MiB Weltdaten.']);
            }
            $result[] = ['template_id' => $template->template_identifier, 'name' => $template->name,
                'revision' => $template->revision, 'bundle_sha256' => $template->bundle_sha256, 'is_selectable' => $template->is_selectable];
        }

        return $result;
    }

    /** @param list<array{template_id: string, name: string, revision: string, bundle_sha256: string, is_selectable: bool}> $snapshot */
    public function registerCommissioned(LuantiServer $server, array $snapshot): void
    {
        DB::transaction(function () use ($server, $snapshot): void {
            foreach ($snapshot as $entry) {
                LuantiWorldTemplate::query()->updateOrCreate(
                    ['luanti_server_id' => $server->id, 'template_identifier' => $entry['template_id']],
                    ['name' => $entry['name'], 'revision' => $entry['revision'], 'is_selectable' => $entry['is_selectable']],
                )->forceFill(['bundle_sha256' => $entry['bundle_sha256'], 'is_available' => true, 'commissioned_at' => now()])->save();
            }
        });
    }

    public function artifact(string $digest): string
    {
        if (! preg_match('/\A[0-9a-f]{64}\z/', $digest)) {
            $this->fail();
        }
        $path = $this->archivePath($digest);
        if (! is_file($path) || is_link($path) || filesize($path) > self::MAX_ARCHIVE_BYTES || hash_file('sha256', $path) !== $digest) {
            throw ValidationException::withMessages(['bundle' => 'Das gespeicherte Vorlagenpaket fehlt oder wurde verändert. Bitte die Portal-Datensicherung prüfen.']);
        }

        return $path;
    }

    /** @return array{template_id: string, name: string, revision: string} */
    private function inspect(string $path): array
    {
        if (! is_file($path) || is_link($path) || filesize($path) > self::MAX_ARCHIVE_BYTES) {
            $this->fail();
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->fail();
        }
        try {
            if ($zip->numFiles < 3 || $zip->numFiles > 6) {
                $this->fail();
            }
            $files = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $name = $entry['name'];
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                if (! in_array($name, ['manifest.json', ...self::CONTENT_FILES], true) || isset($files[$name])
                    || ! in_array(($attributes >> 16) & 0170000, [0, 0100000], true)
                    || $entry['size'] > self::MAX_ARCHIVE_BYTES || ($entry['encryption_method'] ?? 0) !== 0
                    || (in_array($name, ['manifest.json', 'world.mt'], true) && $entry['size'] > 65536)) {
                    $this->fail();
                }
                $total += $entry['size'];
                if ($total > 134217728) {
                    $this->fail();
                }
                $raw = $zip->getFromIndex($index);
                if (! is_string($raw) || strlen($raw) !== $entry['size']) {
                    $this->fail();
                }
                $files[$name] = $raw;
            }
            if (! isset($files['manifest.json'], $files['world.mt'], $files['map.sqlite'])) {
                $this->fail();
            }
            $manifest = json_decode($files['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
            $keys = is_array($manifest) ? array_keys($manifest) : [];
            sort($keys);
            if ($keys !== ['files', 'name', 'revision', 'template_id', 'version'] || ($manifest['version'] ?? null) !== 1
                || ! is_string($manifest['template_id']) || ! preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $manifest['template_id'])
                || ! is_string($manifest['name']) || trim($manifest['name']) === '' || mb_strlen($manifest['name']) > 255
                || ! is_array($manifest['files']) || ! is_string($manifest['revision'])) {
                $this->fail();
            }
            unset($files['manifest.json']);
            $hashes = array_map(fn (string $bytes): string => hash('sha256', $bytes), $files);
            ksort($hashes);
            ksort($manifest['files']);
            $revision = hash('sha256', json_encode(['files' => $hashes, 'name' => $manifest['name'], 'template_id' => $manifest['template_id']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($hashes !== $manifest['files'] || $revision !== $manifest['revision']) {
                $this->fail();
            }
            $this->worldSettings($files['world.mt']);
            foreach (['map.sqlite', 'mod_storage.sqlite'] as $name) {
                if (isset($files[$name])) {
                    $this->sqlite($files[$name], $name === 'mod_storage.sqlite');
                }
            }

            return ['template_id' => $manifest['template_id'], 'name' => $manifest['name'], 'revision' => $revision];
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            $this->fail();
        } finally {
            $zip->close();
        }
    }

    private function worldSettings(string $raw): void
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $this->fail();
        }
        $settings = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            $key = trim($parts[0]);
            if (count($parts) !== 2 || isset($settings[$key])) {
                $this->fail();
            }
            $settings[$key] = trim($parts[1]);
        }
        if (($settings['gameid'] ?? null) !== 'minetest' || ($settings['load_mod_civicrm_bridge'] ?? null) !== 'mods/civicrm_bridge') {
            $this->fail();
        }
        foreach (['backend', 'player_backend', 'auth_backend', 'mod_storage_backend'] as $key) {
            if (($settings[$key] ?? 'sqlite3') !== 'sqlite3') {
                $this->fail();
            }
        }
    }

    private function sqlite(string $raw, bool $modStorage): void
    {
        File::ensureDirectoryExists($this->directory(), 0700);
        $path = tempnam($this->directory(), 'inspect-');
        try {
            File::put($path, $raw);
            $database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $database->exec('PRAGMA query_only=ON');
            if ($database->query('PRAGMA quick_check')->fetchColumn() !== 'ok'
                || ($modStorage && $database->query("SELECT 1 FROM entries WHERE modname='civicrm_bridge' LIMIT 1")->fetchColumn() !== false)) {
                $this->fail();
            }
        } finally {
            $database = null;
            File::delete($path);
        }
    }

    private function directory(): string
    {
        return Storage::disk('local')->path('world-templates');
    }

    /** Keep full SHA-256 identities within Windows archive path limits. */
    private function archivePath(string $digest): string
    {
        $filename = rtrim(strtr(base64_encode(hex2bin($digest)), '+/', '-_'), '=');

        return $this->directory().'/'.$filename.'.zip';
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['bundle' => 'Bitte ein gültiges veröffentlichtes Vorlagen-ZIP mit manifest.json und ausschließlich sauberen Weltdaten auswählen.']);
    }
}
