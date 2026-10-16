<?php

namespace Tests\Unit;

use App\Services\BoundedBridgeResponseStream;
use App\Services\BridgeHttpClient;
use App\Services\BridgeResponseTooLargeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BoundedBridgeResponseStreamTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function acceptedSizes(): array
    {
        return ['empty' => [0], 'below limit' => [BridgeHttpClient::MAX_RESPONSE_BYTES - 1], 'at limit' => [BridgeHttpClient::MAX_RESPONSE_BYTES]];
    }

    #[DataProvider('acceptedSizes')]
    public function test_accepts_bounded_bodies_and_closes_storage(int $size): void
    {
        $sink = new BoundedBridgeResponseStream;
        $this->assertSame($size, $sink->write(str_repeat('x', $size)));
        $this->assertSame($size, $sink->getSize());
        $this->assertFalse($sink->exceededLimit());
        $sink->close();
        $this->assertFalse($sink->isWritable());
    }

    /** @return array<string, array{int}> */
    public static function writeSizes(): array
    {
        return ['single oversized write' => [BridgeHttpClient::MAX_RESPONSE_BYTES + 1], 'small writes' => [1024], 'uneven writes' => [8191]];
    }

    #[DataProvider('writeSizes')]
    public function test_rejects_overflow_before_storing_the_offending_chunk(int $chunkSize): void
    {
        $sink = new BoundedBridgeResponseStream;
        try {
            for ($attempt = 0; $attempt <= intdiv(BridgeHttpClient::MAX_RESPONSE_BYTES, $chunkSize); $attempt++) {
                $before = $sink->getSize();
                $sink->write(str_repeat('x', $chunkSize));
            }
            $this->fail('The overflowing write must be rejected.');
        } catch (BridgeResponseTooLargeException $exception) {
            $this->assertTrue($sink->exceededLimit());
            $this->assertSame($before, $sink->getSize());
            $this->assertLessThanOrEqual(BridgeHttpClient::MAX_RESPONSE_BYTES, $sink->getSize());
            $this->assertSame('Bridge response exceeds the permitted size.', $exception->getMessage());
        } finally {
            $sink->close();
        }
    }

    public function test_rejects_one_byte_after_exactly_filling_the_sink(): void
    {
        $sink = new BoundedBridgeResponseStream;
        $sink->write(str_repeat('x', BridgeHttpClient::MAX_RESPONSE_BYTES));
        try {
            $sink->write('x');
            $this->fail('The extra byte must not be stored.');
        } catch (BridgeResponseTooLargeException) {
            $this->assertSame(BridgeHttpClient::MAX_RESPONSE_BYTES, $sink->getSize());
        } finally {
            $sink->close();
        }
    }
}
