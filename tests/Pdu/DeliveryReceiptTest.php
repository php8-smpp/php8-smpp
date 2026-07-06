<?php

declare(strict_types=1);

namespace Pdu;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\SmppException;
use Smpp\Exceptions\SmppInvalidArgumentException;
use Smpp\Pdu\Address;
use Smpp\Pdu\DeliveryReceipt;
use Smpp\Protocol\Command;
use Smpp\Smpp;

class DeliveryReceiptTest extends TestCase
{
    /**
     * Happy path with the common 10-digit date format (YYMMDDhhmm).
     * Also exercises an alphanumeric message id, which previously triggered a
     * TypeError because `$id` was declared `int` while preg_match yields strings.
     *
     * @throws SmppException
     * @throws SmppInvalidArgumentException
     */
    public function testParseDeliveryReceiptWithTenDigitDates(): void
    {
        $receipt = $this->makeReceipt(
            'id:abc123XYZ sub:001 dlvrd:001 submit date:1601011200 done date:1601011230 stat:DELIVRD err:000 text:Hello world'
        );

        $receipt->parseDeliveryReceipt();

        self::assertSame('abc123XYZ', $receipt->messageId);
        self::assertSame(1, $receipt->sub);
        self::assertSame(1, $receipt->dlvrd);
        // 2016-01-01 12:00:00 UTC, 2016-01-01 12:30:00 UTC
        self::assertSame(1451649600, $receipt->submitDate);
        self::assertSame(1451651400, $receipt->doneDate);
        self::assertSame('DELIVRD', $receipt->stat);
        self::assertSame(0, $receipt->err);
        self::assertSame('Hello world', $receipt->text);
    }

    /**
     * 12-digit date format (YYMMDDhhmmss) — seconds are honoured rather than
     * silently truncated.
     *
     * @throws SmppException
     * @throws SmppInvalidArgumentException
     */
    public function testParseDeliveryReceiptWithTwelveDigitDates(): void
    {
        $receipt = $this->makeReceipt(
            'id:42 sub:002 dlvrd:002 submit date:160101120015 done date:160101123045 stat:EXPIRED err:042 text:'
        );

        $receipt->parseDeliveryReceipt();

        self::assertSame('42', $receipt->messageId);
        self::assertSame(2, $receipt->sub);
        self::assertSame(2, $receipt->dlvrd);
        // 2016-01-01 12:00:15 UTC, 2016-01-01 12:30:45 UTC
        self::assertSame(1451649615, $receipt->submitDate);
        self::assertSame(1451651445, $receipt->doneDate);
        self::assertSame('EXPIRED', $receipt->stat);
        self::assertSame(42, $receipt->err);
        self::assertSame('', $receipt->text);
    }

    /**
     * 11-digit dates were silently accepted by the old `\d{10,12}` pattern,
     * producing garbage timestamps via str_split byte misalignment.
     */
    public function testParseDeliveryReceiptRejectsElevenDigitDate(): void
    {
        $receipt = $this->makeReceipt(
            'id:abc sub:001 dlvrd:001 submit date:16010112000 done date:1601011230 stat:DELIVRD err:000 text:x'
        );

        $this->expectException(SmppInvalidArgumentException::class);
        $receipt->parseDeliveryReceipt();
    }

    public function testParseDeliveryReceiptRejectsMalformedMessage(): void
    {
        $receipt = $this->makeReceipt('not a delivery receipt');

        $this->expectException(SmppInvalidArgumentException::class);
        $receipt->parseDeliveryReceipt();
    }

    private function makeReceipt(string $message): DeliveryReceipt
    {
        $source      = new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
        $destination = new Address('5678', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);

        return new DeliveryReceipt(
            id: Command::DELIVER_SM,
            status: 0,
            sequence: 1,
            body: '',
            serviceType: '',
            source: $source,
            destination: $destination,
            esmClass: Smpp::ESM_DELIVER_SMSC_RECEIPT,
            protocolId: 0,
            priorityFlag: 0,
            registeredDelivery: 0,
            dataCoding: Smpp::DATA_CODING_DEFAULT,
            message: $message,
            tags: []
        );
    }
}
