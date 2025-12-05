<?php

declare(strict_types=1);


namespace Smpp;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Smpp\Configs\SmppConfig;
use Smpp\Configs\SocketTransportConfig;
use Smpp\Configs\StreamTransportConfig;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Transport\SCTPTransport;
use Smpp\Transport\SocketTransport;
use Smpp\Transport\StreamTransport;
use Smpp\Utils\Network\DSNParser;

class ClientBuilder
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var TransportInterface
     */
    private TransportInterface $transport;
    /**
     * @var SmppConfig
     */
    private SmppConfig $config;
    /**
     * @var string
     */
    private string $systemId;
    /**
     * @var string
     */
    private string $password;

    final private function __construct()
    {
        $this->logger = new NullLogger();
        $this->config = new SmppConfig();
    }

    /**
     * @param string[] $dsnEntries
     * @param SocketTransportConfig|null $config
     *
     * @return static
     * @throws Exceptions\SmppInvalidArgumentException
     */
    public static function createForSockets(array $dsnEntries, SocketTransportConfig $config = null): static
    {
        $self = new static();

        if (!isset($config)) {
            $config = new SocketTransportConfig();
        }

        $self->transport = new SocketTransport(DSNParser::parseDSNEntries(...$dsnEntries), $config);

        return $self;
    }

    /**
     * @param string[] $dsnEntries
     * @param SocketTransportConfig|null $config
     *
     * @return static
     * @throws Exceptions\SmppInvalidArgumentException
     */
    public static function createForSCTP(array $dsnEntries, SocketTransportConfig $config = null): static
    {
        $self = new static();

        if (!isset($config)) {
            $config = new SocketTransportConfig();
        }

        $self->transport = new SCTPTransport(DSNParser::parseDSNEntries(...$dsnEntries), $config);

        return $self;
    }

    /**
     * @param string[] $dsnEntries
     * @param StreamTransportConfig|null $config
     *
     * @return static
     * @throws Exceptions\SmppInvalidArgumentException
     */
    public static function createForStream(array $dsnEntries, StreamTransportConfig $config = null): static
    {
        $self = new static();

        if (!isset($config)) {
            $config = new StreamTransportConfig();
        }

        $self->transport = new StreamTransport(DSNParser::parseDSNEntries(...$dsnEntries), $config);

        return $self;
    }


    /**
     * @param LoggerInterface $logger
     *
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param SmppConfig $config
     *
     * @return $this
     */
    public function setClientConfig(SmppConfig $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @param string $systemId
     * @param string $password
     *
     * @return $this
     */
    public function setCredentials(string $systemId, string $password): self
    {
        $this->systemId = $systemId;
        $this->password = $password;
        return $this;
    }

    /**
     * @return Client
     */
    public function buildClient(): Client
    {
        if (isset($this->transport->logger) && $this->transport->logger instanceof LoggerInterface) {
            $this->transport->logger = $this->logger;
        }

        $client = new Client(
            $this->transport,
            $this->systemId,
            $this->password,
        );

        $client->logger = $this->logger;
        $client->config = $this->config;

        return $client;
    }
}