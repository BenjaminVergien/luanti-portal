<?php

namespace App\Services;

use App\Models\LuantiServer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LuantiDockerInstallation
{
    public function __construct(private PortalAccess $access, private HetznerVirtualMachines $vms, private LuantiSshClient $ssh) {}

    /** @return array{successful: bool, title: string, body: string} */
    public function verify(User $actor, LuantiServer $server): array
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('update', $server);
        $server = $server->fresh();
        if ($server->luanti_docker_image === null || $server->provisioning_host_public_key === null) {
            throw ValidationException::withMessages(['installation' => 'Diese VM wurde ohne automatische Luanti-Installation erstellt. Bitte eine neue VM mit ausgewähltem Image erstellen.']);
        }
        $server = $this->vms->refresh($actor, $server);
        $result = Cache::lock('hetzner:vm:'.$server->id, 120)->get(function () use ($actor, $server): array {
            $server = $server->fresh();
            Gate::forUser($this->access->currentUser($actor))->authorize('update', $server);
            if ($server->hetzner_status !== 'running') {
                return $this->result(false, 'VM noch nicht bereit', 'Die VM muss laufen, bevor ihre Installation geprüft werden kann.');
            }
            try {
                $output = $this->ssh->execute($server, file_get_contents(resource_path('provisioning/verify_luanti.py')));
            } catch (ValidationException $exception) {
                return $this->record($actor, $server, 'pending', $exception->errors()['installation'][0]);
            }
            $data = json_decode($output, true);
            if (! is_array($data) || ($data['version'] ?? null) !== 1) {
                return $this->record($actor, $server, 'failed', 'Die VM hat kein gültiges Installationsergebnis geliefert.');
            }
            if (($data['status'] ?? null) === 'waiting') {
                return $this->record($actor, $server, 'pending', 'Die Einrichtung startet noch. Bitte in Kürze erneut prüfen.');
            }
            if (($data['reference'] ?? null) !== $server->hetzner_reference || ($data['image'] ?? null) !== $server->luanti_docker_image) {
                return $this->record($actor, $server, 'failed', 'Das Installationsergebnis passt nicht zu dieser VM und ihrem ausgewählten Image.');
            }
            if (($data['status'] ?? null) === 'installing') {
                return $this->record($actor, $server, 'installing', 'Docker und Luanti werden noch installiert. Bitte später erneut prüfen.');
            }
            if (($data['status'] ?? null) !== 'verified'
                || ! is_string($data['image_id'] ?? null) || ! preg_match('/\Asha256:[0-9a-f]{64}\z/', $data['image_id'])
                || ! is_string($data['engine_version'] ?? null) || strlen($data['engine_version']) > 200
                || ! preg_match('/\A(?:Luanti|Minetest) \d+\.\d+[A-Za-z0-9 ._()+\/-]*\z/', $data['engine_version'])) {
                return $this->record($actor, $server, 'failed', 'Die Installation oder der anschließende Engine-Test ist fehlgeschlagen.');
            }
            Gate::forUser($this->access->currentUser($actor))->authorize('update', $server);
            $server->forceFill([
                'luanti_installation_status' => 'verified', 'luanti_installation_error' => null,
                'luanti_image_id' => $data['image_id'], 'luanti_engine_version' => $data['engine_version'], 'luanti_verified_at' => now(),
            ])->save();

            return $this->result(true, 'Luanti-Engine geprüft', $data['engine_version'].' – Image vorhanden und Engine erfolgreich ausgeführt.');
        });

        return is_array($result) ? $result : $this->result(false, 'VM wird gerade bearbeitet', 'Bitte die Prüfung in Kürze erneut starten.');
    }

    /** @return array{successful: bool, title: string, body: string} */
    private function record(User $actor, LuantiServer $server, string $state, string $message): array
    {
        Gate::forUser($this->access->currentUser($actor))->authorize('update', $server);
        $server->forceFill(['luanti_installation_status' => $state, 'luanti_installation_error' => $message, 'luanti_verified_at' => null])->save();

        return $this->result(false, $server->installationStatusLabel(), $message);
    }

    /** @return array{successful: bool, title: string, body: string} */
    private function result(bool $successful, string $title, string $body): array
    {
        return ['successful' => $successful, 'title' => $title, 'body' => $body];
    }
}
