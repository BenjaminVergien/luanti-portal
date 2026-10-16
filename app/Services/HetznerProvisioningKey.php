<?php

namespace App\Services;

use App\Models\LuantiServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Validation\ValidationException;

class HetznerProvisioningKey
{
    public function __construct(private HetznerCloudClient $http, private SshKeyPair $keys) {}

    /** The VM lifecycle service holds the server lock and authorizes the actor. */
    public function ensureRegistered(LuantiServer $server): int
    {
        $this->ensureKeyPair($server);
        $key = $this->findRegisteredKey($server);
        if ($key === null) {
            $server->forceFill(['hetzner_ssh_key_id' => null])->save();
            $response = $this->request('POST', 'ssh_keys', [
                'name' => $this->keyName($server), 'public_key' => $server->provisioning_public_key,
            ]);
            if ($response->status() !== 201) {
                $this->fail('Der SSH-Schlüssel konnte bei Hetzner nicht registriert werden. Bitte API-Zugang und Schreibrechte prüfen und die Einrichtung erneut versuchen.');
            }
            $key = $response->json('ssh_key');
        }
        $this->validateRegisteredKey($server, $key);
        $server->forceFill(['hetzner_ssh_key_id' => $key['id']])->save();

        return $key['id'];
    }

    public function removeRegisteredKey(LuantiServer $server): void
    {
        if ($server->provisioning_public_key === null) {
            return;
        }
        $key = $this->findRegisteredKey($server);
        if ($key === null) {
            return;
        }
        $this->validateRegisteredKey($server, $key);
        $response = $this->request('DELETE', 'ssh_keys/'.$key['id']);
        if (! $response->successful() && $response->status() !== 404) {
            $this->fail('Der SSH-Schlüssel konnte bei Hetzner noch nicht entfernt werden. Bitte den VM-Status erneut aktualisieren.');
        }
    }

    private function ensureKeyPair(LuantiServer $server): void
    {
        if ($server->getRawOriginal('provisioning_private_key') !== null) {
            if (blank($server->provisioning_public_key)) {
                $this->fail('Der gespeicherte Provisionierungsschlüssel ist unvollständig.');
            }

            return;
        }
        if ($server->provisioning_public_key !== null) {
            $this->fail('Der private Provisionierungsschlüssel fehlt. Bestehende Schlüssel werden nicht automatisch ersetzt.');
        }
        $pair = $this->keys->generate();
        $server->forceFill(['provisioning_private_key' => $pair['private'], 'provisioning_public_key' => $pair['public']])->save();
    }

    /** @return array<string, mixed>|null */
    private function findRegisteredKey(LuantiServer $server): ?array
    {
        $response = $server->hetzner_ssh_key_id === null
            ? $this->request('GET', 'ssh_keys', ['name' => $this->keyName($server), 'per_page' => 2])
            : $this->request('GET', 'ssh_keys/'.$server->hetzner_ssh_key_id);
        if ($server->hetzner_ssh_key_id !== null && $response->status() === 404) {
            return null;
        }
        if (! $response->ok()) {
            $this->fail('Der SSH-Schlüssel konnte bei Hetzner nicht geprüft werden. Bitte später erneut versuchen.');
        }
        if ($server->hetzner_ssh_key_id !== null) {
            $key = $response->json('ssh_key');
        } else {
            $keys = $response->json('ssh_keys');
            if ($keys === []) {
                return null;
            }
            $key = is_array($keys) && count($keys) === 1 ? $keys[0] : null;
        }
        $this->validateRegisteredKey($server, $key);

        return $key;
    }

    private function validateRegisteredKey(LuantiServer $server, mixed $key): void
    {
        if (! is_array($key) || ! is_int($key['id'] ?? null) || $key['id'] < 1
            || ($server->hetzner_ssh_key_id !== null && $key['id'] !== $server->hetzner_ssh_key_id)
            || ($key['name'] ?? null) !== $this->keyName($server) || ! is_string($key['public_key'] ?? null)
            || $this->keyMaterial($key['public_key']) !== $this->keyMaterial($server->provisioning_public_key)) {
            $this->fail('Der SSH-Schlüssel bei Hetzner lässt sich dieser VM nicht eindeutig zuordnen.');
        }
    }

    private function keyName(LuantiServer $server): string
    {
        return 'luanti-portal-'.$server->hetzner_reference;
    }

    private function keyMaterial(string $publicKey): string
    {
        return implode(' ', array_slice(explode(' ', trim($publicKey)), 0, 2));
    }

    /** @param array<string, mixed> $data */
    private function request(string $method, string $path, array $data = []): Response
    {
        try {
            return $this->http->request($method, $path, $data);
        } catch (ConnectionException) {
            $this->fail('Hetzner ist nicht erreichbar. Der SSH-Schlüssel bleibt gespeichert und wird beim nächsten Versuch erneut geprüft.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['hetzner' => $message]);
    }
}
