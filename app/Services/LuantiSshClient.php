<?php

namespace App\Services;

use App\Models\LuantiServer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\Process\Exception\ExceptionInterface;

class LuantiSshClient
{
    public function execute(#[SensitiveParameter] LuantiServer $server, #[SensitiveParameter] string $script, int $timeoutSeconds = 25, int $maxOutputBytes = 16384): string
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 1800 || $maxOutputBytes < 1 || $maxOutputBytes > 1048576) {
            throw new \InvalidArgumentException('Invalid SSH execution limits.');
        }

        return $this->connection($server, $script, $timeoutSeconds, $maxOutputBytes);
    }

    public function tunnel(#[SensitiveParameter] LuantiServer $server, ?callable $connected = null): void
    {
        $this->connection($server, '', null, 16384, $connected);
    }

    private function connection(#[SensitiveParameter] LuantiServer $server, #[SensitiveParameter] string $script, ?int $timeoutSeconds, int $maxOutputBytes, ?callable $connected = null): string
    {
        if (! filter_var($server->hetzner_ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || blank($server->getRawOriginal('provisioning_private_key'))
            || ! preg_match('/\Assh-rsa [A-Za-z0-9+\/=]+(?: [^\r\n]*)?\z/', $server->provisioning_host_public_key ?? '')
            || ! preg_match('/\A[0-9a-f-]{36}\z/', $server->hetzner_reference ?? '')) {
            $this->fail('Für diese VM fehlt die sichere SSH-Konfiguration. Bitte eine neue VM mit ausgewähltem Luanti-Image erstellen.');
        }
        $binary = config('services.provisioning.ssh_binary');
        if (! is_string($binary) || ! is_file($binary)) {
            $this->fail('Der OpenSSH-Client fehlt auf dem Portal-Host. Bitte SSH_BINARY konfigurieren.');
        }
        File::ensureDirectoryExists(storage_path('app/private'), 0700);
        $directory = storage_path('app/private/ssh-'.bin2hex(random_bytes(16)));
        if (! mkdir($directory, 0700)) {
            $this->fail('Die private SSH-Arbeitsumgebung konnte nicht erstellt werden.');
        }
        $keyFile = $directory.'/identity';
        $knownHosts = $directory.'/known_hosts';
        $knownHostsOption = '"'.str_replace(['\\', '"'], ['/', '\\"'], $knownHosts).'"';
        $hostAlias = 'luanti-portal-'.$server->hetzner_reference;
        $stage = 'identity';
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $systemDirectory = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\';
                $identity = Process::timeout(5)->run([$systemDirectory.'whoami.exe']);
                if (! $identity->successful() || blank(trim($identity->output()))) {
                    $this->fail('Die SSH-Dateiberechtigungen konnten nicht eingerichtet werden.');
                }
                $stage = 'permissions';
                $permissions = Process::timeout(5)->run([$systemDirectory.'icacls.exe', $directory, '/inheritance:r',
                    '/grant:r', trim($identity->output()).':(OI)(CI)F']);
                if (! $permissions->successful()) {
                    $this->fail('Die SSH-Dateiberechtigungen konnten nicht eingerichtet werden.');
                }
            }
            if (file_put_contents($keyFile, $server->provisioning_private_key, LOCK_EX) === false
                || file_put_contents($knownHosts, $hostAlias.' '.$server->provisioning_host_public_key."\n", LOCK_EX) === false) {
                $this->fail('Die privaten SSH-Dateien konnten nicht erstellt werden.');
            }
            chmod($keyFile, 0600);
            chmod($knownHosts, 0600);
            $stage = 'verification';
            $arguments = [
                $binary, '-F', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', '-T', '-p', '22',
                '-i', $keyFile, '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
                '-o', 'StrictHostKeyChecking=yes', '-o', 'HostKeyAlgorithms=rsa-sha2-512,rsa-sha2-256',
                '-o', 'UpdateHostKeys=no',
                '-o', 'UserKnownHostsFile='.$knownHostsOption, '-o', 'GlobalKnownHostsFile='.$knownHostsOption,
                '-o', 'HostKeyAlias='.$hostAlias, '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1',
                '-o', 'ServerAliveInterval=5', '-o', 'ServerAliveCountMax=2', '-o', 'LogLevel=ERROR',
            ];
            if ($timeoutSeconds === null) {
                $listener = @stream_socket_server('tcp://127.0.0.1:18443', $errorNumber, $errorMessage);
                if ($listener === false) {
                    throw new \RuntimeException('Port 18443 is already in use. Close the existing bridge tunnel before starting another.');
                }
                fclose($listener);
                $arguments = [...$arguments, '-N', '-L', '127.0.0.1:18443:127.0.0.1:8443', '-o', 'ExitOnForwardFailure=yes', 'root@'.$server->hetzner_ipv4];
                $captured = 0;
                $running = Process::forever()->start($arguments, function (string $type, string $output) use (&$captured, $maxOutputBytes): void {
                    $captured += strlen($output);
                    if ($captured > $maxOutputBytes) {
                        throw new \LengthException('SSH tunnel output exceeded its configured limit.');
                    }
                });
                $stopping = false;
                $handler = static function () use (&$stopping): bool {
                    $stopping = true;

                    return true;
                };
                if (function_exists('sapi_windows_set_ctrl_handler')) {
                    sapi_windows_set_ctrl_handler($handler, true);
                }
                try {
                    $deadline = microtime(true) + 10;
                    $established = false;
                    while ($running->running() && ! $stopping) {
                        $running->ensureNotTimedOut();
                        if (! $established) {
                            $socket = @stream_socket_client('tcp://127.0.0.1:18443', $errorNumber, $errorMessage, 0.1);
                            if ($socket !== false) {
                                fclose($socket);
                                $established = true;
                                unlink($keyFile);
                                unlink($knownHosts);
                                rmdir($directory);
                                if ($connected !== null) {
                                    $connected();
                                    $stopping = true;
                                }
                            } elseif (microtime(true) >= $deadline) {
                                throw new \RuntimeException('The private SSH tunnel did not become ready.');
                            }
                        }
                        usleep(100000);
                    }
                    if ($stopping) {
                        $running->stop(2);
                    }
                    $result = $running->wait();
                } finally {
                    if ($running->running()) {
                        $running->stop(2);
                    }
                    if (function_exists('sapi_windows_set_ctrl_handler')) {
                        sapi_windows_set_ctrl_handler($handler, false);
                    }
                }
                if ($stopping) {
                    return '';
                }
            } else {
                $captured = 0;
                $result = Process::timeout($timeoutSeconds)->input($script)->run(
                    [...$arguments, 'root@'.$server->hetzner_ipv4, 'python3 -'],
                    function (string $type, string $output) use (&$captured, $maxOutputBytes): void {
                        $captured += strlen($output);
                        if ($captured > $maxOutputBytes) {
                            throw new \LengthException('SSH output exceeded its configured limit.');
                        }
                    },
                );
            }
            if (! $result->successful() || strlen($result->output()) > $maxOutputBytes) {
                Log::warning('Luanti SSH verification failed', [
                    'server_id' => $server->id, 'exit_code' => $result->exitCode(),
                ]);
                $this->fail('Die SSH-Prüfung ist fehlgeschlagen. Die VM startet möglicherweise noch. Bitte später erneut prüfen; die gespeicherte Host-Identität bleibt verbindlich.');
            }

            return $result->output();
        } catch (ExceptionInterface $exception) {
            Log::warning('Luanti SSH process failed', [
                'server_id' => $server->id, 'stage' => $stage, 'exception_type' => $exception::class,
                'reason' => substr($exception->getMessage(), 0, 1500),
            ]);
            if (str_contains($exception->getMessage(), 'A temporary file could not be opened')) {
                $this->fail('Der Portal-Host kann keine temporären Prozessdateien schreiben. Bitte TEMP/TMP bzw. sys_temp_dir auf ein beschreibbares Verzeichnis setzen und den lokalen Entwicklungsserver neu starten.');
            }
            $this->fail('Die SSH-Prüfung konnte auf dem Portal-Host nicht abgeschlossen werden (Schritt: '.$stage.'). Die technische Ursache steht im Portal-Protokoll.');
        } finally {
            foreach ([$keyFile, $knownHosts] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['installation' => $message]);
    }
}
