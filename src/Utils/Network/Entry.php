<?php

declare(strict_types=1);

namespace Smpp\Utils\Network;

use Smpp\Exceptions\SmppInvalidArgumentException;

class Entry
{
    /**
     * Entry constructor.
     *
     * @param int $port
     * @param string|null $ipv4
     * @param string|null $ipv6
     *
     * @throws SmppInvalidArgumentException
     */
    public function __construct(
        private int $port,
        private ?string $ipv4 = null,
        private ?string $ipv6 = null,
        private ?string $host = null,

    )
    {
        if ($port < 1 || $port > 65535) {
            throw new SmppInvalidArgumentException('Invalid port number provided');
        }

        if (is_null($this->ipv4) && is_null($this->ipv6)) {
            throw new SmppInvalidArgumentException('Addresses not provided');
        }
    }

    /**
     * @return string|null
     */
    public function getIpv4(): ?string
    {
        return $this->ipv4;
    }

    /**
     * @return string|null
     */
    public function getIpv6(): ?string
    {
        return $this->ipv6;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return string|null
     */
    public function getHost(): string
    {
        return $this->host;
    }    
}