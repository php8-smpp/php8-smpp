<?php

declare(strict_types=1);

namespace Smpp\Pdu;

use Smpp\Exceptions\SmppException;
use Smpp\Exceptions\SmppInvalidArgumentException;

/**
 * An extension of a SMS, with data embedded into the message part of the SMS.
 * @author hd@onlinecity.dk
 */
class DeliveryReceipt extends Sms
{
    public string $messageId;
    public int    $sub;
    public int    $dlvrd;
    public int    $submitDate;
    public int    $doneDate;
    public string $stat;
    public int    $err;
    public string $text;

    /**
     * Parse a delivery receipt formatted as specified in SMPP v3.4 - Appendix B
     * It accepts all chars except space as the message id.
     *
     * Date fields are accepted as either YYMMDDhhmm (10 digits, no seconds) or
     * YYMMDDhhmmss (12 digits) per spec / common SMSC implementations.
     *
     * @throws SmppInvalidArgumentException
     * @throws SmppException
     */
    public function parseDeliveryReceipt(): void
    {
        $numMatches = preg_match(
            '/^id:([^ ]+) sub:(\d{1,3}) dlvrd:(\d{3}) submit date:(\d{10}|\d{12}) done date:(\d{10}|\d{12}) stat:([A-Z ]{7}) err:(\d{2,3}) text:(.*)$/si',
            $this->message,
            $matches
        );
        if ($numMatches === 0) {
            throw new SmppInvalidArgumentException(
                'Could not parse delivery receipt: '
                . $this->message
                . "\n"
                . bin2hex($this->getBody())
            );
        }

        $this->messageId  = $matches[1];
        $this->sub        = (int)$matches[2];
        $this->dlvrd      = (int)$matches[3];
        $this->submitDate = $this->convertDate($matches[4]);
        $this->doneDate   = $this->convertDate($matches[5]);
        $this->stat       = $matches[6];
        $this->err        = (int)$matches[7];
        $this->text       = $matches[8];
    }

    /**
     * Convert a YYMMDDhhmm[ss] datetime string into a UTC unix timestamp.
     *
     * @throws SmppException
     */
    private function convertDate(string $date): int
    {
        $dateParts = str_split($date, 2);
        // The receipt regex guarantees 10 or 12 digits → 5 or 6 chunks, but
        // the type system can't prove that — guard explicitly so PHPStan can
        // narrow array access below and we get a clear runtime error if
        // convertDate is ever called from a path that skips the regex.
        if (count($dateParts) < 5) {
            throw new SmppException('Invalid date provided');
        }

        $timestamp = gmmktime(
            (int)$dateParts[3],
            (int)$dateParts[4],
            (int)($dateParts[5] ?? '0'),
            (int)$dateParts[1],
            (int)$dateParts[2],
            (int)$dateParts[0]
        );

        if ($timestamp === false) {
            throw new SmppException('Invalid date provided');
        }
        return $timestamp;
    }
}
