<?php

declare(strict_types=1);

namespace Pdu;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\SmppInvalidArgumentException;
use Smpp\Pdu\Tag;

class TagTest extends TestCase
{
    /**
     * Round-trip: pack a string-valued TLV and verify the binary matches the
     * raw `nn` + value layout (id, length, value) defined by SMPP v3.4 5.3.
     *
     * @throws SmppInvalidArgumentException
     */
    public function testGetBinaryPacksStringValueTlv(): void
    {
        $value = 'Hello';
        $tag   = new Tag(Tag::MESSAGE_PAYLOAD, $value);

        $binary = $tag->getBinary();

        $expected = pack('nn', Tag::MESSAGE_PAYLOAD, strlen($value)) . $value;
        $this->assertSame($expected, $binary);
    }

    /**
     * Numeric TLV with explicit pack-type (single-byte unsigned char).
     *
     * @throws SmppInvalidArgumentException
     */
    public function testGetBinaryPacksIntegerValueTlv(): void
    {
        $tag = new Tag(Tag::SAR_SEGMENT_SEQNUM, 3, 1, 'c');

        $binary = $tag->getBinary();

        $expected = pack('nnc', Tag::SAR_SEGMENT_SEQNUM, 1, 3);
        $this->assertSame($expected, $binary);
    }

    /**
     * pack() in PHP 8 throws ValueError on a malformed format string instead
     * of returning false. getBinary() must convert that into the documented
     * SmppInvalidArgumentException for callers.
     */
    public function testGetBinaryThrowsSmppExceptionOnInvalidPackFormat(): void
    {
        $tag = new Tag(Tag::USER_MESSAGE_REFERENCE, 'value', 5, 'k');

        $this->expectException(SmppInvalidArgumentException::class);
        $tag->getBinary();
    }
}
