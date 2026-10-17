<?php

namespace App\Services;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;

class BoundedBridgeResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $bytesWritten = 0;

    private bool $exceeded = false;

    public function __construct(private int $limit = BridgeHttpClient::MAX_RESPONSE_BYTES)
    {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'w+'));
    }

    public function write(#[SensitiveParameter] string $string): int
    {
        if ($this->exceeded || strlen($string) > $this->limit - $this->bytesWritten) {
            $this->exceeded = true;

            throw new BridgeResponseTooLargeException;
        }

        $written = $this->stream->write($string);
        $this->bytesWritten += $written;

        return $written;
    }

    public function exceededLimit(): bool
    {
        return $this->exceeded;
    }
}
