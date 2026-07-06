<?php

declare(strict_types=1);

namespace Smpp;

use DateInterval;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Smpp\Configs\SmppConfig;
use Smpp\Contracts\Client\SmppClientInterface;
use Smpp\Contracts\Middlewares\MiddlewareInterface;
use Smpp\Contracts\Pdu\PduInterface;
use Smpp\Contracts\Pdu\PduResponseInterface;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\ClosedTransportException;
use Smpp\Exceptions\SmppException;
use Smpp\Exceptions\SmppInvalidArgumentException;
use Smpp\Exceptions\SocketTransportException;
use Smpp\Pdu\Address;
use Smpp\Pdu\BinaryPDU;
use Smpp\Pdu\DeliveryReceipt;
use Smpp\Pdu\Pdu;
use Smpp\Pdu\PDUHeader;
use Smpp\Pdu\Sms;
use Smpp\Pdu\Tag;
use Smpp\Protocol\Command;
use Smpp\Protocol\CommandStatus;
use Smpp\Protocol\PDUBuilder;
use Smpp\Protocol\PDUParser;

/**
 * Class for receiving or sending sms through the SMPP protocol.
 * This is a reduced implementation of the SMPP protocol, and as such, not all features will or ought to be available.
 * The purpose is to create a lightweight and simplified SMPP client.
 *
 * @author hd@onlinecity.dk, paladin, Alexandr Mironov
 * @see http://en.wikipedia.org/wiki/Short_message_peer-to-peer_protocol - SMPP 3.4 protocol specification
 * Derived from work done by paladin, see: http://sourceforge.net/projects/phpsmppapi/
 *
 * Copyright (C) 2020 Alexandr Mironov
 * Copyright (C) 2011 OnlineCity
 * Copyright (C) 2006 Paladin
 *
 * This library is free software; you can redistribute it and/or modify it under the terms of
 * the GNU Lesser General Public License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Lesser General Public License for more details.
 *
 * This license can be read at: http://www.opensource.org/licenses/lgpl-2.1.php
 */
class Client implements SmppClientInterface
{
    // Available modes
    /** @var string */
    private const MODE_TRANSMITTER = 'transmitter';

    /** @var string */
    private const MODE_TRANSCEIVER = 'transceiver';

    /** @var string */
    private const MODE_RECEIVER = 'receiver';
    /**
     * @var LoggerInterface
     */
    public LoggerInterface $logger;

    // Used for reconnect
    /**
     * @var SmppConfig
     */
    public SmppConfig $config;
    /** @var Pdu[] */
    protected array $pduQueue = [];
    /** @var string */
    protected string $mode;
    /** @var int */
    protected int $sequenceNumber = 1;
    /** @var int */
    protected int $sarMessageReferenceNumber;
    /**
     * @var PDUParser
     */
    private PDUParser $parser;
    /**
     * @var PDUBuilder
     */
    private PDUBuilder $builder;

    /**
     * Construct the SMPP Client class
     *
     * @param TransportInterface $transport
     * @param string $systemId
     * @param string $password
     */
    public function __construct(
        public TransportInterface $transport,
        private string $systemId,
        private string $password
    )
    {
        $this->config = new SmppConfig();
        $this->logger = new NullLogger();

        $this->builder = new PDUBuilder($this->logger);
        $this->parser  = new PDUParser($this->logger);
    }

    /**
     * Query the SMSC about the current state/status of a previously sent SMS.
     * You must specify the SMSC-assigned message ID and source of the sent SMS.
     * Returns an associative array with elements: message_id, final_date, message_state and error_code.
     *    message_state would be one of the SMPP::STATE_* constants. (SMPP v3.4 section 5.2.28)
     *    error_code depends on the telco network, so it could be anything.
     *
     * @param string $messageID
     * @param Address $source
     *
     * @return null|array<string, mixed>
     *
     * @throws Exception
     */
    public function queryStatus(string $messageID, Address $source): null|array
    {
        $pduBody = pack(
            'a' . (strlen($messageID) + 1) . 'cca' . (strlen($source->getValue()) + 1),
            $messageID,
            $source->getNumberType(),
            $source->getNumberingPlanIndicator(),
            $source->getValue()
        );

        $reply = $this->sendCommand(Command::QUERY_SM, $pduBody);

        if ($reply->getStatus() !== CommandStatus::ESME_ROK) {
            return null;
        }

        // Parse reply
        $posID   = strpos($reply->getBody(), "\0", 0);
        $posDate = strpos($reply->getBody(), "\0", $posID + 1);

        if ($posID === false) {
            $this->logger->debug(
                "Invalid response",
                [
                    'body hex' => $reply->getBody(),
                ]
            );
            throw new SmppException('Invalid response');
        }

        $data               = [
            'message_id' => substr($reply->getBody(), 0, $posID),
            'final_date' => substr($reply->getBody(), $posID, (int)$posDate - $posID),
        ];
        $data['final_date'] = $data['final_date'] ? $this->parseSmppTime(trim($data['final_date'])) : null;
        /** @var false|array{message_state: mixed, error_code: mixed} $status */
        $status = unpack("cmessage_state/cerror_code", substr($reply->getBody(), $posDate + 1));

        if (!$status) {
            $this->logger->debug(
                "unable to unpack message_state & error_code",
                [
                    'body hex' => $reply->getBody(),
                ]
            );
            throw new SmppException('Invalid response');
        }

        return array_merge($data, $status);
    }

    /**
     * Sends the PDU command to the SMSC and waits for a response.
     * @param int $id - command ID
     * @param ?string $pduBody - PDU body
     * @return Pdu
     *
     * @throws Exception
     */
    protected function sendCommand(int $id, ?string $pduBody): Pdu
    {
        if (!$this->transport->isOpen()) {
            throw new SocketTransportException('Socket is closed');
            //return false;
        }
        $pdu = new Pdu($id, 0, $this->sequenceNumber, $pduBody);
        $this->sendPDU($pdu);
        $response = $this->readPduResponse($this->sequenceNumber, $pdu->getId());

        if ($response === false) {
            throw new SmppException('Failed to read reply to command: 0x' . dechex($id));
        }

        if ($response->getStatus() != CommandStatus::ESME_ROK) {
            throw new SmppException(CommandStatus::getStatusMessageByCode($response->getStatus()), $response->getStatus());
        }

        $this->sequenceNumber++;

        // Reached max sequence number, spec does not state what happens now, so we re-connect
        if ($this->sequenceNumber >= 0x7FFFFFFF) {
            $this->reconnect();
        }

        return $response;
    }

    /**
     * Prepares and sends PDU to SMSC.
     * @param Pdu $pdu
     * @throws Exception
     */
    protected function sendPDU(Pdu $pdu): void
    {
        $binaryPdu = $this->builder->packPdu($pdu);
        $this->transport->write($binaryPdu->getData(), $binaryPdu->getLength());
    }

    /**
     * Waits for SMSC response on specific PDU.
     * If a GENERIC_NACK with a matching sequence number, or null sequence, is received instead, it's also accepted.
     * Some SMPP servers, ie. logica returns GENERIC_NACK on errors.
     *
     * @param int $sequenceNumber - PDU sequence number
     * @param int $commandID - PDU command ID
     *
     * @return Pdu|false
     * @throws SmppException
     */
    protected function readPduResponse(int $sequenceNumber, int $commandID): Pdu|false
    {
        // Get response cmd id from command ID
        $commandID = $commandID | Command::GENERIC_NACK;

        // Check the queue first
        $queueLength = count($this->pduQueue);
        for ($i = 0; $i < $queueLength; $i++) {
            $pdu = $this->pduQueue[$i];
            if ($this->isExpectedResponse($pdu, $sequenceNumber, $commandID)) {
                // remove response pdu from queue
                array_splice($this->pduQueue, $i, 1);
                return $pdu;
            }
        }

        // Read PDUs until the one we are looking for shows up, or a generic nack pdu with matching sequence or null sequence
        do {
            $pdu = $this->readPDU();
            if ($pdu) {
                if ($this->isExpectedResponse($pdu, $sequenceNumber, $commandID)) {
                    return $pdu;
                }
                array_push($this->pduQueue, $pdu); // unknown PDU push to queue
            }
        } while ($pdu);

        return false;
    }

    /**
     * @param Pdu $pdu
     * @param int $sequenceNumber
     * @param int $commandID
     *
     * @return bool
     */
    private function isExpectedResponse(Pdu $pdu, int $sequenceNumber, int $commandID): bool
    {
        return $pdu->getSequence() === $sequenceNumber
            && ($pdu->getId() === $commandID || $pdu->getId() === Command::GENERIC_NACK);
    }

    /**
     * Reads incoming PDU from SMSC.
     * @return false|Pdu
     * @throws SmppException
     */
    protected function readPDU(): Pdu|false
    {
        // Read PDU header
        $bufHeaders = $this->transport->read(PDUHeader::PDU_HEADER_LENGTH);
        if ($bufHeaders === "") {
            return false;
        }

        // Parse PDU header to get body length and read all PDU
        $pduHeader  = $this->parser->parsePduHeader($bufHeaders);
        $bodyLength = $pduHeader->getCommandLength() - PDUHeader::PDU_HEADER_LENGTH;

        // Read PDU body
        $body = null;
        if ($bodyLength > 0) {
            // if body is not empty, read them from the socket
            $body = $this->transport->read($bodyLength);
            if (strlen($body) === 0) {
                throw new SmppException('Could not read PDU body');
            }
        }

        $this->logger->debug("Read PDU         : {$pduHeader->getCommandLength()} bytes");
        $this->logger->debug(' ' . chunk_split(bin2hex($bufHeaders . $body), 2, " "));
        $this->logger->debug(" command id      : 0x" . dechex($pduHeader->getCommandId()));
        $this->logger->debug(" command status  : 0x"
            . dechex($pduHeader->getCommandStatus())
            . " "
            . CommandStatus::getStatusMessageByCode($pduHeader->getCommandStatus())
        );
        $this->logger->debug(' sequence number : ' . $pduHeader->getSequenceNumber());

        return new Pdu(
            id: $pduHeader->getCommandId(),
            status: $pduHeader->getCommandStatus(),
            sequence: $pduHeader->getSequenceNumber(),
            body: $body
        );
    }

    /**
     * Reconnect to SMSC.
     * This is mainly to deal with the situation where we run out of sequence numbers
     *
     * @throws SmppException|Exception
     */
    protected function reconnect(): void
    {
        $this->close();
        usleep($this->config->getReconnectSleepTime());
        $this->transport->open();
        $this->sequenceNumber = 1;

        match ($this->mode) {
            self::MODE_TRANSMITTER => $this->bindTransmitter(),
            self::MODE_RECEIVER => $this->bindReceiver(),
            self::MODE_TRANSCEIVER => $this->bindTransceiver(),
            default => throw new SmppException('Invalid mode: ' . $this->mode)
        };
    }

    /**
     * Closes the session on the SMSC server.
     *
     * @return void
     * @throws Exception
     */
    public function close(): void
    {
        if (!$this->transport->isOpen()) {
            return;
        }

        $this->logger->debug('Unbinding...');

        $response = $this->sendCommand(Command::UNBIND, "");

        $this->logger->debug("Unbind status   : " . $response->getStatus());

        $this->transport->close();
    }

    /**
     * Binds the transmitter. One object can be bound only as a receiver or only as a transmitter.
     *
     * @return void
     * @throws Exception
     */
    public function bindTransmitter(): void
    {
        if (!$this->transport->isOpen()) {
            $this->transport->open();
        }

        $this->logger->debug('Binding transmitter...');

        $response = $this->bind(Command::BIND_TRANSMITTER);

        $this->logger->debug("Binding status  : " . $response->getStatus());

        $this->mode = self::MODE_TRANSMITTER;
    }

    /**
     * Binds the socket and opens the session on SMSC
     *
     * @param int $commandID
     *
     * @return Pdu
     *
     * @throws Exception
     */
    protected function bind(int $commandID): Pdu
    {
        // Make PDU body
        $pduBody = pack(
            'a' . (strlen($this->systemId) + 1)
            . 'a' . (strlen($this->password) + 1)
            . 'a' . (strlen($this->config->getSystemType()) + 1)
            . 'CCCa' . (strlen($this->config->getAddressRange()) + 1),
            $this->systemId,
            $this->password,
            $this->config->getSystemType(),
            $this->config->getInterfaceVersion(),
            $this->config->getAddressNumberType(),
            $this->config->getAddressNumberingPlanIndicator(),
            $this->config->getAddressRange()
        );

        $response = $this->sendCommand($commandID, $pduBody);
        if ($response->getStatus() != CommandStatus::ESME_ROK) {
            throw new SmppException(CommandStatus::getStatusMessageByCode($response->getStatus()), $response->getStatus());
        }

        return $response;
    }

    /**
     * Binds the receiver. One object can be bound only as a receiver or only as a transmitter.
     *
     * @return void
     *
     * @throws ClosedTransportException
     * @throws Exception
     */
    public function bindReceiver(): void
    {
        if (!$this->transport->isOpen()) {
            $this->transport->open();
        }

        $this->logger->debug('Binding receiver...');

        $response = $this->bind(Command::BIND_RECEIVER);

        $this->logger->debug("Binding status  : " . $response->getStatus());

        $this->mode = self::MODE_RECEIVER;
    }

    /**
     * Bind transceiver, this object is bound as receiver and transmitter at the same time,
     * only if available in the SMPP gateway
     *
     * @return void
     * @throws Exception
     */
    public function bindTransceiver(): void
    {
        if (!$this->transport->isOpen()) {
            $this->transport->open();
        }

        $this->logger->debug('Binding transceiver...');

        $response = $this->bind(Command::BIND_TRANSCEIVER);

        $this->logger->debug("Binding status  : " . $response->getStatus());

        $this->mode = self::MODE_TRANSCEIVER;
    }

    /**
     * Parse a time string as formatted by SMPP v3.4 section 7.1.
     * Returns an object of either DateTime or DateInterval.
     *
     * @param string $input
     *
     * @return DateTime|DateInterval|null
     *
     * @throws Exception
     */
    public function parseSmppTime(string $input): null|DateTime|DateInterval
    {
        if (
        !preg_match(
            '/^(\\d{2})(\\d{2})(\\d{2})(\\d{2})(\\d{2})(\\d{2})(\\d{1})(\\d{2})([R+-])$/',
            $input,
            $matches
        )
        ) {
            return null;
        }

        /**
         * @var int $y
         * @var int $m
         * @var int $d
         * @var int $h
         * @var int $i
         * @var int $s
         * @var int $n
         * @var string $p
         */
        [$whole, $y, $m, $d, $h, $i, $s, $t, $n, $p] = $matches;

        if ($p == 'R') {
            $spec = "P";
            if ($y) {
                $spec .= $y . 'Y';
            }
            if ($m) {
                $spec .= $m . 'M';
            }
            if ($d) {
                $spec .= $d . 'D';
            }
            if ($h || $i || $s) {
                $spec .= 'T';
            }
            if ($h) {
                $spec .= $h . 'H';
            }
            if ($i) {
                $spec .= $i . 'M';
            }
            if ($s) {
                $spec .= $s . 'S';
            }
            return new DateInterval($spec);
        } else {
            $offsetHours   = floor($n / 4);
            $offsetMinutes = ($n % 4) * 15;
            // Not Y3K safe
            $time = sprintf(
                "20%02s-%02s-%02sT%02s:%02s:%02s%s%02s:%02s",
                $y,
                $m,
                $d,
                $h,
                $i,
                $s,
                $p,
                $offsetHours,
                $offsetMinutes
            );
            return new DateTime($time);
        }
    }

    /**
     * Read one SMS from SMSC. Can be executed only after the bindReceiver() call.
     * This method blocks. The method returns on a socket timeout or an enquire_link signal from the SMSC.
     *
     * @return DeliveryReceipt|Sms|bool
     * @throws Exception
     */
    public function readSMS(): bool|DeliveryReceipt|Sms
    {
        // Check the queue
        $queueLength = count($this->pduQueue);
        for ($i = 0; $i < $queueLength; $i++) {
            $pdu = $this->pduQueue[$i];
            if ($pdu->getId() === Command::DELIVER_SM) {
                //remove response
                array_splice($this->pduQueue, $i, 1);
                return $this->parseSMS($pdu);
            }
        }
        // Read pdu
        do {
            $pdu = $this->readPDU();
            if ($pdu === false) {
                return false;
            } // TSocket v. 0.6.0+ returns false on timeout
            //check for the enquire link command
            if ($pdu->getId() === Command::ENQUIRE_LINK) {
                $response = new Pdu(Command::ENQUIRE_LINK_RESP, CommandStatus::ESME_ROK, $pdu->getSequence(), "\x00");
                $this->sendPDU($response);
            } else if ($pdu->getId() !== Command::DELIVER_SM) { // if this is not the correct PDU add to queue
                array_push($this->pduQueue, $pdu);
            }
        } while ($pdu->getId() !== Command::DELIVER_SM);

        return $this->parseSMS($pdu);
    }

    /**
     * Parse received PDU from SMSC.
     * @param Pdu $pdu - received PDU from SMSC.
     *
     * @return DeliveryReceipt|Sms parsed PDU as array.
     *
     * @throws Exception
     */
    protected function parseSMS(Pdu $pdu): DeliveryReceipt|Sms
    {
        // Check command id
        if ($pdu->getId() != Command::DELIVER_SM) {
            throw new SmppInvalidArgumentException('PDU is not an received SMS');
        }

        $sms = $this->parser->parseSms($pdu);

        $this->logger->debug("Received sms:\n" . print_r($sms, true));

        // Send a response of receiving sms
        $response = new Pdu(Command::DELIVER_SM_RESP, CommandStatus::ESME_ROK, $pdu->getSequence(), "\x00");
        $this->sendPDU($response);
        return $sms;
    }

    /**
     * Send one SMS to SMSC. Can be executed only after bindTransmitter() call.
     * $message is always in octets regardless of the data encoding.
     * For correct handling of Concatenated SMS,
     * message must be encoded with GSM 03.38 (data_coding 0x00) or UCS-2BE (0x08).
     * Concatenated SMSes use 16-bit reference numbers, which gives 152 GSM 03.38 chars or 66 UCS-2BE chars per CSMS.
     * If we are using 8-bit ref numbers in the UDH for CSMS, it's 153 GSM 03.38 chars
     *
     * @param Address $from
     * @param Address $to
     * @param string $message
     * @param Tag[]|null $tags (optional)
     * @param int $dataCoding (optional)
     * @param int $priority (optional)
     * @param null $scheduleDeliveryTime (optional)
     * @param null $validityPeriod (optional)
     *
     * @return bool|string message id
     *
     * @throws Exception
     */
    public function sendSMS(
        Address $from,
        Address $to,
        string $message,
        array $tags = null,
        int $dataCoding = Smpp::DATA_CODING_DEFAULT,
        int $priority = 0x00,
        $scheduleDeliveryTime = null,
        $validityPeriod = null
    ): bool|string
    {
        $messageLength = strlen($message);

        if ($messageLength > 160 && !in_array($dataCoding, [Smpp::DATA_CODING_UCS2, Smpp::DATA_CODING_DEFAULT])) {
            return false;
        }

        switch ($dataCoding) {
            case Smpp::DATA_CODING_UCS2:
                // in octets, 70 UCS-2 chars
                $singleSmsOctetLimit = 140;
                // There are 133 octets available, but this would split the UCS in the middle, so use 132 instead
                $csmsSplit = 132;
                /**
                 * Convert message to UTF-16 encoding for proper SMPP UCS-2 compatibility
                 *
                 * Uses UTF-16 instead of basic UCS-2 to support:
                 * - Modern Unicode characters (emojis, symbols beyond BMP)
                 * - Surrogate pairs (required for characters above U+FFFF)
                 * - Full compliance with SMPP spec which actually expects UTF-16BE
                 *   despite referring to it as "UCS-2" (common industry practice)
                 *
                 * Note: UTF-16BE is explicitly used rather than system-dependent UTF-16
                 * to ensure consistent big-endian byte ordering as required by SMPP.
                 *
                 * @see SMPP v3.4+ specification section 5.2.19 (data_coding interpretation)
                 */
                $message = mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');
                //Update message length with current encoding
                $messageLength = strlen($message);
                break;
            case Smpp::DATA_CODING_DEFAULT:
                //We send data in octets, but GSM 03.38 will be packed in septets (7-bit) by SMSC.
                $singleSmsOctetLimit = 160;
                // send 152/153 chars in each SMS (SMSC will format data)
                $csmsSplit = ($this->config->getCsmsMethod() === Smpp::CSMS_8BIT_UDH) ? 153 : 152;
                break;
            default:
                $singleSmsOctetLimit = 254; // From SMPP standard
                break;
        }

        // Figure out if we need to do CSMS, since it will affect our PDU
        if ($messageLength > $singleSmsOctetLimit) {
            $doCsms = true;
            if ($this->config->getCsmsMethod() !== Smpp::CSMS_PAYLOAD) {
                $parts        = $this->splitMessageString($message, $csmsSplit ?? 132, $dataCoding);
                $shortMessage = reset($parts);
            }
        } else {
            $shortMessage = $message;
            $doCsms       = false;
        }

        // Deal with CSMS
        if ($doCsms) {
            if ($this->config->getCsmsMethod() === Smpp::CSMS_PAYLOAD) {
                $payload = new Tag(Tag::MESSAGE_PAYLOAD, $message, $messageLength);
                $tags[]  = $payload;
                return $this->submitShortMessage(
                    $from,
                    $to,
                    null,
                    $tags,
                    $dataCoding,
                    $priority,
                    $scheduleDeliveryTime,
                    $validityPeriod
                );
            } elseif ($this->config->getCsmsMethod() === Smpp::CSMS_8BIT_UDH && isset($parts)) {
                $sequenceNumber = 1;
                $csmsReference = $this->getCsmsReference();
                $partCount = count($parts);
                foreach ($parts as $part) {
                    $userDataHeader = pack(
                        'CCCCCC',
                        5,
                        0,
                        3,
                        $csmsReference,
                        $partCount,
                        $sequenceNumber
                    );
                    $res            = $this->submitShortMessage(
                        $from,
                        $to,
                        $userDataHeader . $part,
                        $tags,
                        $dataCoding,
                        $priority,
                        $scheduleDeliveryTime,
                        $validityPeriod,
                        (string) ($this->config->getSmsEsmClass() | 0x40) //todo: check this
                    );
                    $sequenceNumber++;
                }
                return $res ?? "";
            } else {
                $sarMessageRefNumber = new Tag(Tag::SAR_MSG_REF_NUM, $this->getCsmsReference(), 2, 'n');
                $sarTotalSegments    = new Tag(Tag::SAR_TOTAL_SEGMENTS, count($parts ?? []), 1, 'c');
                $sequenceNumber      = 1;
                foreach ($parts ?? [] as $part) {
                    $sartags = [
                        $sarMessageRefNumber,
                        $sarTotalSegments,
                        new Tag(Tag::SAR_SEGMENT_SEQNUM, $sequenceNumber, 1, 'c')
                    ];
                    $res     = $this->submitShortMessage(
                        $from,
                        $to,
                        (string)$part,
                        (empty($tags) ? $sartags : array_merge($tags, $sartags)),
                        $dataCoding,
                        $priority,
                        $scheduleDeliveryTime,
                        $validityPeriod
                    );
                    $sequenceNumber++;
                }
                return $res ?? "";
            }
        }

        return $this->submitShortMessage($from, $to, (string)($shortMessage ?? ''), $tags, $dataCoding, $priority);
    }

    /**
     * Split a message into multiple parts, taking the encoding into account.
     * A character represented by a GSM 03.38 escape sequence shall not be split in the middle.
     * Uses str_split if at all possible, and will examine all split points for escape chars if it's required.
     *
     * @param string $message
     * @param int<1,max> $chunkSize
     * @param integer $dataCoding (optional)
     *
     * @return string[]
     */
    protected function splitMessageString(
        string $message,
        int $chunkSize,
        int $dataCoding = Smpp::DATA_CODING_DEFAULT
    ): array
    {
        switch ($dataCoding) {
            case Smpp::DATA_CODING_DEFAULT:
                $messageLength = strlen($message);
                // Do we need to do a PHP-based split?
                $numParts = floor($messageLength / $chunkSize);
                if ($messageLength % $chunkSize == 0) {
                    $numParts--;
                }
                $slowSplit = false;

                for ($i = 1; $i <= $numParts; $i++) {
                    if ($message[$i * $chunkSize - 1] == "\x1B") {
                        $slowSplit = true;
                        break;
                    }
                }
                if (!$slowSplit) {
                    return str_split($message, $chunkSize);
                }

                // Split the message char-by-char
                $parts = [];
                $part  = "";
                $n     = 0;
                for ($i = 0; $i < $messageLength; $i++) {
                    $c = $message[$i];
                    // reset on $quantSize or if last char is a GSM 03.38 escape char
                    if ($n == $chunkSize || ($n == ($chunkSize - 1) && $c == "\x1B")) {
                        $parts[] = $part;
                        $n       = 0;
                        $part    = "";
                    }
                    $part .= $c;
                }
                $parts[] = $part;
                return $parts;
            /**
             * UCS2-BE can just use str_split since we send 132 octets per message,
             * which gives a fine split using UCS2
             */
            case Smpp::DATA_CODING_UCS2:
            default:
                return str_split($message, $chunkSize);
        }
    }

    /**
     * Perform the actual submit_sm call to send SMS.
     * Implemented as a protected method to enable automatic SMS concatenation.
     * Tags must be an array of already packed and encoded TLV-params.
     *
     * @param Address $source
     * @param Address $destination
     * @param string|null $shortMessage
     * @param Tag[]|null $tags
     * @param integer $dataCoding
     * @param integer $priority
     * @param string|null $scheduleDeliveryTime
     * @param string|null $validityPeriod
     * @param string|null $esmClass
     *
     * @return string message id
     *
     * @throws Exception
     */
    protected function submitShortMessage(
        Address $source,
        Address $destination,
        string $shortMessage = null,
        array $tags = null,
        int $dataCoding = Smpp::DATA_CODING_DEFAULT,
        int $priority = 0x00,
        string $scheduleDeliveryTime = null,
        string $validityPeriod = null,
        string $esmClass = null
    ): string
    {
        if (is_null($esmClass)) {
            $esmClass = $this->config->getSmsEsmClass();
        }

        $shortMessageLength = strlen((string)$shortMessage);
        // Construct PDU with mandatory fields
        $pdu = pack(
            'a1cca' . (strlen($source->getValue()) + 1)
            . 'cca' . (strlen($destination->getValue()) + 1)
            . 'ccc' . ($scheduleDeliveryTime ? 'a16x' : 'a1') . ($validityPeriod ? 'a16x' : 'a1')
            . 'ccccca' . ($shortMessageLength + (int)$this->config->isSmsNullTerminateOctetstrings()),
            $this->config->getSmsServiceType(),
            $source->getNumberType(),
            $source->getNumberingPlanIndicator(),
            $source->getValue(),
            $destination->getNumberType(),
            $destination->getNumberingPlanIndicator(),
            $destination->getValue(),
            $esmClass,
            $this->config->getSmsProtocolID(),
            $priority,
            $scheduleDeliveryTime,
            $validityPeriod,
            $this->config->getSmsRegisteredDeliveryFlag(),
            $this->config->getSmsReplaceIfPresentFlag(),
            $dataCoding,
            $this->config->getSmsSmDefaultMessageID(),
            $shortMessageLength, //sm_length
            $shortMessage //short_message
        );

        // Add any tags
        if (!empty($tags)) {
            foreach ($tags as $tag) {
                $pdu .= $tag->getBinary();
            }
        }

        $response = $this->sendCommand(Command::SUBMIT_SM, $pdu);
        /** @var array{msgid: string}|false $body */
        $body = unpack("a*msgid", $response->getBody());
        if (!$body) {
            throw new SmppException('unable to unpack response body:' . $response->getBody());
        }
        return $body['msgid'];
    }

    /**
     * Get a CSMS reference number for sar_msg_ref_num.
     * Initializes with a random value, and then returns the number in sequence with each call.
     *
     * @return int
     */
    protected function getCsmsReference(): int
    {
        $limit = ($this->config->getCsmsMethod() === Smpp::CSMS_8BIT_UDH) ? 255 : 65535;
        if (!isset($this->sarMessageReferenceNumber)) {
            $this->sarMessageReferenceNumber = mt_rand(0, $limit);
        }
        $this->sarMessageReferenceNumber++;

        if ($this->sarMessageReferenceNumber > $limit) {
            $this->sarMessageReferenceNumber = 0;
        }
        return $this->sarMessageReferenceNumber;
    }

    /**
     * Send the enquire link command.
     * @return Pdu
     * @throws Exception
     */
    public function enquireLink(): Pdu
    {
        return $this->sendCommand(Command::ENQUIRE_LINK, null);
    }

    /**
     * Respond to any enquire link we might have waiting.
     * If will check the queue first and respond to any enquire links we have there.
     * Then it will move on to the transport, and if the first PDU is an enquire link response,
     * otherwise add it to the queue and return.
     *
     * @throws Exception
     */
    public function respondEnquireLink(): void
    {
        // Check the queue first
        $queueLength = count($this->pduQueue);
        for ($i = 0; $i < $queueLength; $i++) {
            $pdu = $this->pduQueue[$i];
            if ($pdu->getId() == Command::ENQUIRE_LINK) {
                //remove response
                array_splice($this->pduQueue, $i, 1);
                $this->sendEnquireLinkResponse($pdu->getSequence());
            }
        }

        // Check the transport for data
        if ($this->transport->hasData()) {
            $pdu = $this->readPDU();
            if ($pdu && $pdu->getId() == Command::ENQUIRE_LINK) {
                $this->sendEnquireLinkResponse($pdu->getSequence());
            } elseif ($pdu) {
                array_push($this->pduQueue, $pdu);
            }
        }
    }

    /**
     * @param int $sequence
     * @throws Exception
     */
    private function sendEnquireLinkResponse(int $sequence): void
    {
        $this->sendBinaryPdu($this->builder->getEnquireLinkResponse($sequence));
    }

    protected function sendBinaryPdu(BinaryPDU $pdu): void
    {
        $this->transport->write($pdu->getData(), $pdu->getLength());
    }

    /**
     * @param PduInterface $pdu
     * @return PduResponseInterface
     * @throws SmppException
     */
    public function send(PduInterface $pdu): PduResponseInterface
    {
        throw new SmppException('Method not implemented');
    }

    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        // TODO: Implement addMiddleware() method.
    }
}
