<?php

namespace App\Services;

use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils as StreamUtils;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;
use Throwable;

class BridgeHttpClient
{
    public const int MAX_RESPONSE_BYTES = 256 * 1024;

    public function send(string $method, string $url, #[SensitiveParameter] string $token, int $timeoutSeconds, #[SensitiveParameter] ?string $body = null): Response
    {
        $managed = app(ProvisionedBridgeTransport::class);
        if ($managed->handles($url)) {
            return $managed->send($method, $url, $token, $timeoutSeconds, $body);
        }
        $sink = new BoundedBridgeResponseStream;
        $response = null;

        try {
            $handler = $this->transportHandler();
            $client = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout($timeoutSeconds)->withoutRedirecting()
                ->setHandler(function (RequestInterface $request, array $options) use ($handler, $sink): PromiseInterface {
                    $options['sink'] = $sink;
                    $options['stream'] = false;
                    $options['decode_content'] = true;

                    return $handler($request, $options);
                });
            $certificate = config('services.bridge_development.ca_certificate');
            $parts = parse_url($url);
            if (app()->environment('local') && config('services.bridge_development.origin') === 'https://localhost:18443'
                && is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'localhost'
                && ($parts['port'] ?? null) === 18443 && ! isset($parts['user']) && ! isset($parts['pass'])
                && is_string($certificate) && is_file($certificate)) {
                $client->withOptions(['verify' => $certificate, 'proxy' => '']);
            }
            if ($body !== null) {
                $client->withBody($body, 'application/json');
            }

            $response = $client->send($method, $url);
            if ($sink->exceededLimit()) {
                throw new BridgeResponseTooLargeException;
            }
            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $contents = '';
            while (! $stream->eof()) {
                $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES - strlen($contents) + 1));
                if (strlen($chunk) > self::MAX_RESPONSE_BYTES - strlen($contents)) {
                    throw new BridgeResponseTooLargeException;
                }
                if ($chunk === '' && ! $stream->eof()) {
                    throw new ConnectionException('Bridge response could not be read.');
                }
                $contents .= $chunk;
            }

            $boundedResponse = new Response($response->toPsrResponse()->withBody(StreamUtils::streamFor($contents)));
            $boundedResponse->cookies = $response->cookies;
            $boundedResponse->transferStats = $response->transferStats;

            return $boundedResponse;
        } catch (Throwable $exception) {
            if ($sink->exceededLimit()) {
                throw new BridgeResponseTooLargeException;
            }
            if ($exception instanceof TransferException || $exception instanceof RequestException) {
                throw new ConnectionException('Bridge response transfer failed.');
            }

            throw $exception;
        } finally {
            $response?->close();
            $sink->close();
        }
    }

    protected function transportHandler(): callable
    {
        return Utils::chooseHandler();
    }
}
