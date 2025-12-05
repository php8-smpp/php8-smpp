<?php

declare(strict_types=1);

namespace Smpp\Configs;

class StreamTransportConfig
{
    private int $defaultSendTimeout = 1000;
    private int $defaultRecvTimeout = 10000;
    private int $defaultConnectTimeout = 15000;
    private bool $forceIpv6 = false;
    private bool $forceIpv4 = false;
    private bool $randomHost = false;
    private bool $useTls = false;
    private ?string $certificateFile = null;

    public function getDefaultSendTimeout(): int
    {
        return $this->defaultSendTimeout;
    }

    public function setDefaultSendTimeout(int $timeout): self
    {
        $this->defaultSendTimeout = $timeout;
        return $this;
    }

    public function getDefaultRecvTimeout(): int
    {
        return $this->defaultRecvTimeout;
    }

    public function setDefaultRecvTimeout(int $timeout): self
    {
        $this->defaultRecvTimeout = $timeout;
        return $this;
    }

    public function getDefaultConnectTimeout(): int
    {
        return $this->defaultConnectTimeout;
    }

    public function setDefaultConnectTimeout(int $timeout): self
    {
        $this->defaultConnectTimeout = $timeout;
        return $this;
    }

    public function getUseTls(): bool
    {
        return $this->useTls;
    }

    public function setUseTls(bool $useTls): self
    {
        $this->useTls = $useTls;
        return $this;
    }

    public function isForceIpv6(): bool
    {
        return $this->forceIpv6;
    }

    public function setForceIpv6(bool $force): self
    {
        $this->forceIpv6 = $force;
        return $this;
    }

    public function isForceIpv4(): bool
    {
        return $this->forceIpv4;
    }

    public function setForceIpv4(bool $force): self
    {
        $this->forceIpv4 = $force;
        return $this;
    }

    public function getCertificateFile(): ?string
    {
        return $this->certificateFile;
    }

    public function setCertificateFile(?string $certificateFile): self
    {
        $this->certificateFile = $certificateFile;
        return $this;
    }

    public function isRandomHost(): bool
    {
        return $this->randomHost;
    }

    public function setRandomHost(bool $random): self
    {
        $this->randomHost = $random;
        return $this;
    }
}
