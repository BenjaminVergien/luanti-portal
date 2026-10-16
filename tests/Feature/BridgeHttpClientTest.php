<?php

namespace Tests\Feature;

use App\Services\BridgeHttpClient;
use App\Services\BridgeResponseTooLargeException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BridgeHttpClientTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function boundedSizes(): array
    {
        return ['empty' => [0], 'below limit' => [262143], 'at limit' => [262144]];
    }

    #[DataProvider('boundedSizes')]
    public function test_returns_readable_bounded_fake_responses_and_closes_the_source(int $size): void
    {
        $closed = false;
        $storage = Utils::streamFor(str_repeat('x', $size));
        $source = FnStream::decorate($storage, [
            'close' => function () use (&$closed, $storage): void {
                $closed = true;
                $storage->close();
            },
        ]);
        Http::preventStrayRequests();
        Http::fake(['*' => Create::promiseFor(new PsrResponse(200, [], $source))]);

        $response = app(BridgeHttpClient::class)->send('GET', 'https://bridge.example.test', 'secret', 8);

        $this->assertTrue($closed);
        $this->assertFalse($storage->isReadable());
        $this->assertSame(str_repeat('x', $size), $response->body());
        $this->assertSame($size, strlen($response->body()));
    }

    public function test_stops_reading_a_nonseekable_custom_body_at_the_first_excess_byte(): void
    {
        $read = 0;
        $closed = false;
        $storage = Utils::streamFor('');
        $source = FnStream::decorate($storage, [
            'isSeekable' => fn (): bool => false,
            'eof' => fn (): bool => false,
            'read' => function (int $length) use (&$read): string {
                $read += $length;

                return str_repeat('x', $length);
            },
            'close' => function () use (&$closed, $storage): void {
                $closed = true;
                $storage->close();
            },
        ]);
        Http::preventStrayRequests();
        Http::fake(['*' => Create::promiseFor(new PsrResponse(500, [], $source))]);

        try {
            app(BridgeHttpClient::class)->send('GET', 'https://bridge.example.test', 'secret', 8);
            $this->fail('An unlimited body must be rejected.');
        } catch (BridgeResponseTooLargeException) {
            $this->assertSame(BridgeHttpClient::MAX_RESPONSE_BYTES + 1, $read);
            $this->assertTrue($closed);
            $this->assertFalse($storage->isReadable());
        }
    }

    /** @return array<string, array{bool}> */
    public static function transportFailures(): array
    {
        return ['success' => [false], 'failure' => [true]];
    }

    #[DataProvider('transportFailures')]
    public function test_closes_the_download_sink_after_success_and_transport_failure(bool $fail): void
    {
        $sink = null;
        $handler = function (RequestInterface $request, array $options) use (&$sink, $fail): PromiseInterface {
            $sink = $options['sink'];
            $this->assertFalse($options['stream']);
            $this->assertTrue($options['decode_content']);
            $sink->write('{}');

            return $fail
                ? Create::rejectionFor(new ConnectException('test transport failure', $request))
                : Create::promiseFor(new PsrResponse(200, [], $sink));
        };
        $client = new class($handler) extends BridgeHttpClient
        {
            public function __construct(private mixed $handler) {}

            protected function transportHandler(): callable
            {
                return $this->handler;
            }
        };

        try {
            $response = $client->send('GET', 'https://bridge.example.test', 'secret', 8);
            $this->assertFalse($fail);
            $this->assertSame('{}', $response->body());
        } catch (ConnectionException) {
            $this->assertTrue($fail);
        }

        $this->assertNotNull($sink);
        $this->assertFalse($sink->isWritable());
        $this->assertFalse($sink->isReadable());
    }

    /** @return array<string, array{string, string, int, int}> */
    public static function transportResponses(): array
    {
        $cases = [];
        foreach (['curl', 'stream'] as $handler) {
            foreach (['empty' => 0, 'below' => 262143, 'exact' => 262144] as $name => $size) {
                $cases[$handler.' '.$name] = [$handler, 'length', 200, $size];
            }
            foreach (['none', 'length', 'false-large-length', 'chunked', 'gzip', 'gzip-false-length'] as $mode) {
                foreach ([200, 404, 500] as $status) {
                    $cases[$handler.' '.$mode.' '.$status] = [$handler, $mode, $status, 16 * 1024 * 1024];
                }
            }
            $cases[$handler.' one byte over'] = [$handler, 'chunked', 200, 262145];
            $cases[$handler.' truncated'] = [$handler, 'truncated', 200, 1024];
            $cases[$handler.' slow'] = [$handler, 'slow', 200, 8192];
        }

        return $cases;
    }

    #[DataProvider('transportResponses')]
    public function test_real_transports_bound_downloads_and_release_connections(string $transport, string $mode, int $status, int $size): void
    {
        $server = new Process([PHP_BINARY, '-r', $this->serverCode(), $mode, (string) $status, (string) $size]);
        $server->setTimeout(10);
        $server->start();
        try {
            $server->waitUntil(fn (string $type, string $output): bool => str_contains($server->getOutput(), "\n"));
            $address = trim(explode("\n", $server->getOutput())[0]);
            $handler = $transport === 'curl' ? new CurlHandler : new StreamHandler;
            $client = new class($handler) extends BridgeHttpClient
            {
                public function __construct(private mixed $handler) {}

                protected function transportHandler(): callable
                {
                    return $this->handler;
                }
            };
            $caught = null;
            $response = null;
            try {
                $response = $client->send('GET', 'http://'.$address, 'test-token', $mode === 'slow' ? 1 : 3);
            } catch (BridgeResponseTooLargeException|ConnectionException $exception) {
                $caught = $exception;
            }

            $server->wait();
            $this->assertSame(0, $server->getExitCode(), $server->getErrorOutput());
            $report = json_decode(explode("\n", trim($server->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            if (in_array($mode, ['truncated', 'slow'], true)) {
                $this->assertInstanceOf(ConnectionException::class, $caught);
            } elseif ($size > BridgeHttpClient::MAX_RESPONSE_BYTES) {
                $this->assertInstanceOf(BridgeResponseTooLargeException::class, $caught);
                if ($size > 1024 * 1024) {
                    $this->assertLessThan($size / 2, $report['sent'], 'The peer must observe an early disconnect.');
                }
            } else {
                $this->assertNull($caught);
                $this->assertSame($status, $response->status());
                $this->assertSame(str_repeat('x', $size), $response->body());
                $response->close();
            }
        } finally {
            $server->stop(0);
        }
    }

    private function serverCode(): string
    {
        return <<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($server, false)."\n";
        flush();
        $peer = stream_socket_accept($server, 5);
        stream_set_timeout($peer, 3);
        while (($line = fgets($peer)) !== false && trim($line) !== '') {}
        [$script, $mode, $status, $size] = $argv;
        $size = (int) $size;
        $headers = "HTTP/1.1 $status Test\r\nConnection: close\r\n";
        if ($mode === 'length') { $headers .= "Content-Length: $size\r\n"; }
        if ($mode === 'false-large-length' || $mode === 'truncated') { $headers .= 'Content-Length: '.($size * 2)."\r\n"; }
        if ($mode === 'chunked') { $headers .= "Transfer-Encoding: chunked\r\n"; }
        if (str_starts_with($mode, 'gzip')) { $headers .= "Content-Encoding: gzip\r\n"; }
        if ($mode === 'gzip-false-length') { $headers .= "Content-Length: 65536\r\n"; }
        fwrite($peer, $headers."\r\n");
        $compressor = str_starts_with($mode, 'gzip') ? deflate_init(ZLIB_ENCODING_GZIP) : null;
        $sent = 0;
        while ($sent < $size) {
            $length = min(4096, $size - $sent);
            $chunk = str_repeat('x', $length);
            if ($compressor !== null) { $chunk = deflate_add($compressor, $chunk, ZLIB_SYNC_FLUSH); }
            if ($mode === 'chunked') { $chunk = dechex(strlen($chunk))."\r\n".$chunk."\r\n"; }
            $offset = 0;
            while ($offset < strlen($chunk)) {
                $written = @fwrite($peer, substr($chunk, $offset));
                if ($written === false || $written === 0) { break 2; }
                $offset += $written;
            }
            $sent += $length;
            if ($mode === 'slow') { usleep(1500000); }
            usleep(1000);
        }
        if ($sent === $size && $mode === 'chunked') { @fwrite($peer, "0\r\n\r\n"); }
        if ($sent === $size && $compressor !== null) { @fwrite($peer, deflate_add($compressor, '', ZLIB_FINISH)); }
        fclose($peer);
        fclose($server);
        echo json_encode(['sent' => $sent])."\n";
        PHP;
    }
}
