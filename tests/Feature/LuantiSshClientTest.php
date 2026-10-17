<?php

namespace Tests\Feature;

use App\Models\LuantiServer;
use App\Services\LuantiSshClient;
use App\Services\SshKeyPair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process as NativeProcess;
use Tests\TestCase;

class LuantiSshClientTest extends TestCase
{
    use RefreshDatabase;

    private function server(): LuantiServer
    {
        $pair = app(SshKeyPair::class)->generate();

        return LuantiServer::factory()->hetznerVm()->create([
            'hetzner_ipv4' => '192.0.2.42', 'provisioning_private_key' => $pair['private'], 'provisioning_host_public_key' => $pair['public'],
        ]);
    }

    public function test_ssh_uses_pinned_identity_and_private_files_are_removed_after_success(): void
    {
        $server = $this->server();
        $keyFile = null;
        $knownHosts = null;
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($server, &$keyFile, &$knownHosts) {
            if (str_ends_with($process->command[0], 'whoami.exe')) {
                return Process::result('test-user');
            }
            if (str_ends_with($process->command[0], 'icacls.exe')) {
                return Process::result();
            }
            $arguments = $process->command;
            $keyFile = $arguments[array_search('-i', $arguments, true) + 1];
            $knownHosts = dirname($keyFile).'/known_hosts';
            $this->assertSame($server->provisioning_private_key, file_get_contents($keyFile));
            $this->assertStringContainsString('luanti-portal-'.$server->hetzner_reference.' '.$server->provisioning_host_public_key, file_get_contents($knownHosts));
            $this->assertContains('StrictHostKeyChecking=yes', $arguments);
            $this->assertContains('IdentitiesOnly=yes', $arguments);
            $this->assertContains('root@192.0.2.42', $arguments);
            $this->assertContains('python3 -', $arguments);
            $this->assertSame('verification-script', $process->input);

            return Process::result('{"version":1,"status":"waiting"}');
        });

        $output = app(LuantiSshClient::class)->execute($server, 'verification-script');

        $this->assertSame(['version' => 1, 'status' => 'waiting'], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
        $this->assertFileDoesNotExist($keyFile);
        $this->assertFileDoesNotExist($knownHosts);
        $this->assertDirectoryDoesNotExist(dirname($keyFile));
        Process::assertRan(fn (PendingProcess $process): bool => str_ends_with($process->command[0], PHP_OS_FAMILY === 'Windows' ? 'ssh.exe' : '/ssh'));
    }

    public function test_ssh_failure_is_sanitized_and_temporary_files_are_removed(): void
    {
        $server = $this->server();
        $keyFile = null;
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$keyFile) {
            if (str_ends_with($process->command[0], 'whoami.exe')) {
                return Process::result('test-user');
            }
            if (str_ends_with($process->command[0], 'icacls.exe')) {
                return Process::result();
            }
            $keyFile = $process->command[array_search('-i', $process->command, true) + 1];

            return Process::result(errorOutput: 'private-network-details', exitCode: 255);
        });

        try {
            app(LuantiSshClient::class)->execute($server, 'verification-script');
            $this->fail('Failed SSH connection was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('private-network-details', $exception->errors()['installation'][0]);
        }

        $this->assertFileDoesNotExist($keyFile);
        $this->assertDirectoryDoesNotExist(dirname($keyFile));
        Process::assertRan(fn (PendingProcess $process): bool => in_array('StrictHostKeyChecking=yes', $process->command, true));
    }

    public function test_missing_host_identity_and_invalid_destination_are_rejected_without_starting_processes(): void
    {
        $server = $this->server();
        $server->forceFill(['provisioning_host_public_key' => null, 'hetzner_ipv4' => '-oProxyCommand=evil'])->save();
        Process::preventStrayProcesses();

        try {
            app(LuantiSshClient::class)->execute($server, 'verification-script');
            $this->fail('An invalid SSH destination was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('installation', $exception->errors());
        }

        Process::assertNothingRan();
    }

    /** @return array<string, array{string, string}> */
    public static function localProcessFailures(): array
    {
        return [
            'unwritable temporary directory' => ['A temporary file could not be opened to write the process output: private-details', 'TEMP/TMP'],
            'other process failure' => ['private-details', 'Schritt: verification'],
        ];
    }

    #[DataProvider('localProcessFailures')]
    public function test_local_process_failure_is_diagnosed_without_leaking_details_and_cleans_private_files(string $reason, string $message): void
    {
        $server = $this->server();
        $keyFile = null;
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($reason, &$keyFile) {
            if (str_ends_with($process->command[0], 'whoami.exe')) {
                return Process::result('test-user');
            }
            if (str_ends_with($process->command[0], 'icacls.exe')) {
                return Process::result();
            }
            $keyFile = $process->command[array_search('-i', $process->command, true) + 1];
            $this->assertContains('StrictHostKeyChecking=yes', $process->command);
            throw new RuntimeException($reason);
        });
        Log::shouldReceive('warning')->once()->withArgs(fn (string $title, array $context): bool => $title === 'Luanti SSH process failed'
            && $context['server_id'] === $server->id && $context['stage'] === 'verification' && $context['exception_type'] === RuntimeException::class);

        try {
            app(LuantiSshClient::class)->execute($server, 'verification-script');
            $this->fail('Failed local process was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->errors()['installation'][0]);
            $this->assertStringNotContainsString('private-details', $exception->errors()['installation'][0]);
        }

        $this->assertFileDoesNotExist($keyFile);
        $this->assertDirectoryDoesNotExist(dirname($keyFile));
    }

    public function test_long_running_execution_bounds_output_and_removes_identity_files_on_overflow(): void
    {
        $server = $this->server();
        $keyFile = null;
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$keyFile) {
            if (str_ends_with($process->command[0], 'whoami.exe')) {
                return Process::result('test-user');
            }
            if (str_ends_with($process->command[0], 'icacls.exe')) {
                return Process::result();
            }
            $keyFile = $process->command[array_search('-i', $process->command, true) + 1];
            $this->assertSame(600, $process->timeout);

            return Process::describe()->output(str_repeat('x', 2048));
        });
        try {
            app(LuantiSshClient::class)->execute($server, 'bounded-test-script', 600, 1024);
            $this->fail('Excess SSH output was accepted.');
        } catch (\LengthException|ValidationException $exception) {
            $this->assertStringNotContainsString(str_repeat('x', 20), $exception->getMessage());
        }
        $this->assertFileDoesNotExist($keyFile);
        $this->assertDirectoryDoesNotExist(dirname($keyFile));
    }

    public function test_tunnel_forwards_only_loopback_and_preserves_the_pinned_host_identity(): void
    {
        $server = $this->server();
        $keyFile = null;
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$keyFile) {
            if (str_ends_with($process->command[0], 'whoami.exe')) {
                return Process::result('test-user');
            }
            if (str_ends_with($process->command[0], 'icacls.exe')) {
                return Process::result();
            }
            $keyFile = $process->command[array_search('-i', $process->command, true) + 1];
            $this->assertContains('-N', $process->command);
            $this->assertContains('127.0.0.1:18443:127.0.0.1:8443', $process->command);
            $this->assertContains('ExitOnForwardFailure=yes', $process->command);
            $this->assertContains('StrictHostKeyChecking=yes', $process->command);
            $this->assertContains('UpdateHostKeys=no', $process->command);

            return Process::describe()->iterations(0);
        });
        app(LuantiSshClient::class)->tunnel($server);
        $this->assertFileDoesNotExist($keyFile);
        $this->assertDirectoryDoesNotExist(dirname($keyFile));
    }

    public function test_native_openssh_accepts_generated_key_and_pinned_host_options_without_network_access(): void
    {
        $ssh = config('services.provisioning.ssh_binary');
        $keygen = dirname($ssh).'/ssh-keygen'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (! is_file($ssh) || ! is_file($keygen)) {
            $this->markTestSkipped('Native OpenSSH tools are unavailable.');
        }
        $server = $this->server();
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($keygen, $server) {
            $arguments = $process->command;
            if (str_ends_with($arguments[0], 'whoami.exe') || str_ends_with($arguments[0], 'icacls.exe')) {
                $native = new NativeProcess($arguments);
                $native->mustRun();

                return Process::result($native->getOutput());
            }
            $keyFile = $arguments[array_search('-i', $arguments, true) + 1];
            $publicKey = new NativeProcess([$keygen, '-y', '-f', $keyFile]);
            $publicKey->mustRun();
            $this->assertSame(implode(' ', array_slice(explode(' ', $server->provisioning_host_public_key), 0, 2)), trim($publicKey->getOutput()));
            array_splice($arguments, 1, 0, ['-G']);
            $configuration = new NativeProcess($arguments);
            $configuration->mustRun();
            $this->assertStringContainsString('stricthostkeychecking true', $configuration->getOutput());
            $this->assertStringContainsString('hostkeyalias luanti-portal-'.$server->hetzner_reference, $configuration->getOutput());

            return Process::result('{"version":1,"status":"waiting"}');
        });

        $this->assertSame('waiting', json_decode(app(LuantiSshClient::class)->execute($server, 'verification-script'), true)['status']);
    }
}
