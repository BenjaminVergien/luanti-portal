<?php

namespace Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Console\ServeCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WindowsDevelopmentServerTest extends TestCase
{
    /** @param list<string> $arguments */
    private function developmentProcess(array $arguments): Process
    {
        $command = new class($arguments) extends ServeCommand
        {
            /** @param list<string> $arguments */
            public function __construct(private array $arguments)
            {
                parent::__construct();
            }

            public function probe(): Process
            {
                $this->input = new ArrayInput([], $this->getDefinition());

                return $this->startProcess(true);
            }

            protected function serverCommand(): array
            {
                return $this->arguments;
            }
        };
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        return $command->probe();
    }

    public function test_windows_development_server_preserves_writable_temporary_directory_for_child_processes(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-specific temporary-directory regression.');
        }
        $originalEnvironment = $_ENV;
        $_ENV['TEMP'] = storage_path('framework/testing');
        $_ENV['TMP'] = storage_path('framework/testing');
        try {
            $process = $this->developmentProcess([PHP_BINARY, '-r', 'echo sys_get_temp_dir();']);
            $process->setTimeout(5)->wait();

            $this->assertTrue($process->isSuccessful());
            $this->assertSame(str_replace('/', '\\', storage_path('framework/testing')), $process->getOutput());
        } finally {
            $_ENV = $originalEnvironment;
        }
    }

    public function test_windows_development_server_preserves_system_paths_required_to_start_openssh(): void
    {
        $binary = config('services.provisioning.ssh_binary');
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($binary)) {
            $this->markTestSkipped('Native Windows OpenSSH is unavailable.');
        }
        $originalEnvironment = $_ENV;
        $_ENV['ProgramData'] = getenv('ProgramData');
        $_ENV['ALLUSERSPROFILE'] = getenv('ALLUSERSPROFILE');
        try {
            $process = $this->developmentProcess([$binary, '-V']);
            $process->setTimeout(5)->wait();

            $this->assertTrue($process->isSuccessful());
            $this->assertStringContainsString('OpenSSH', $process->getErrorOutput());
        } finally {
            $_ENV = $originalEnvironment;
        }
    }
}
