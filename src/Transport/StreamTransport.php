<?php

declare(strict_types=1);

namespace Smpp\Transport;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Smpp\Configs\StreamTransportConfig;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\SocketTransportException;
use Smpp\Protocol\ProtocolEnum;
use Smpp\Utils\Network\Entry;

/**
 * Stream-based Transport supporting TLS.
 */
class StreamTransport implements TransportInterface
{
    /** @var ?resource */
    protected $socket = null;
    public LoggerInterface $logger;

    /**
     * StreamTransport constructor.
     *
     * @param Entry[] $entries
     * @param StreamTransportConfig $config
     */
    public function __construct(
        private array $entries,
        private StreamTransportConfig $config,
    ) {
        $this->logger = new NullLogger();

        if ($this->config->isRandomHost()) {
            shuffle($this->entries);
        }
    }

    private function getProtocol(): ProtocolEnum
    {
        if ($this->config->getUseTls()) {
            return ProtocolEnum::TLS;
        } elseif ($this->config->isForceIpv6()) {
            return ProtocolEnum::TCP6;
        } elseif ($this->config->isForceIpv4()) {
            return ProtocolEnum::TCP4;
        } else {
            return ProtocolEnum::TCP;
        }
    }

    public function getHost(Entry $entry): string
    {
        if ($this->config->getUseTls()) {
            return $entry->getHost();
        } elseif ($this->config->isForceIpv6()) {
            return $entry->getIpv6();
        } elseif ($this->config->isForceIpv4()) {
            return $entry->getIpv4();
        } else {
            return $entry->getIpv4();
        }
    }

    private function getStreamContext(): array
    {
        $ctx = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ];

        if ($this->config->getUseTls() && $this->config->getCertificateFile()) {
            $ctx['ssl'] = [...$ctx['ssl'], 'cafile' => $this->config->getCertificateFile()];
        }

        return $ctx;
    }

    public function open(): void
    {
        $lastError = null;

        foreach ($this->entries as $entry) {
            $port = $entry->getPort();
            $host = $this->getHost($entry);
            $protocol = $this->getProtocol()->value;
            $remote = "$protocol://$host:$port";

            $this->logger->debug("Trying to connect to $remote");

            $context = stream_context_create($this->getStreamContext());

            $connectTimeout = $this->millisecToArray($this->config->getDefaultConnectTimeout());
            $socket = stream_socket_client($remote, $errno, $errstr, $connectTimeout['sec'], STREAM_CLIENT_CONNECT, $context);

            if ($socket) {
                stream_set_blocking($socket, true);
                stream_set_timeout($socket, $connectTimeout['sec'], $connectTimeout['usec']);
                $this->socket = $socket;
                $this->logger->debug("Connected to $remote");
                return;
            }

            $lastError = "Failed to connect to $remote: $errstr ($errno)";
            $this->logger->error($lastError);
        }

        throw new SocketTransportException($lastError ?: 'Could not connect to any host');
    }

	public function close(): void
	{
		stream_set_blocking($this->socket, true);

		$r = null;
		$w = [$this->socket];
		$e = null;
		stream_select($r, $w, $e, 1);

		stream_socket_shutdown($this->socket, \STREAM_SHUT_RDWR);
		fclose($this->socket);
	}

	public function isOpen(): bool
	{
		if (!is_resource($this->socket)) {
			return false;
        }

		$r = null;
		$w = null;
		$e = [$this->socket];
		$res = stream_select($r, $w, $e, 0);

		if (false === $res) {
			throw new SocketTransportException("Stream can't connect");
        }

		return empty($e);
	}

    public function hasData(): bool
    {
        if (!$this->socket) {
            return false;
        }

        $read = [$this->socket];
        $write = $except = null;
        $numChanged = stream_select($read, $write, $except, 0, 0);
        
        return $numChanged > 0;
    }

    public function read(int $length): string
    {
        if (!$this->socket) {
            throw new SocketTransportException('Stream is not open');
        }

        if (!is_resource($this->socket) || feof($this->socket)) {
            throw new SocketTransportException('Stream connection error');
        }

        $read = [$this->socket];
        $write = null;
        $except = null;
        $data = null;

        $readTimeout = $this->millisecToArray($this->config->getDefaultRecvTimeout());

        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, $readTimeout['sec'], $readTimeout['usec']);

        if (stream_select($read, $write, $except, $readTimeout['sec'], $readTimeout['usec']) > 0) {
            $data = fread($this->socket, $length);
        } else {
            throw new SocketTransportException('Stream no answer from server');
        }

        if (false === $data) {
            throw new SocketTransportException("Stream could not read $length bytes");
        }

        return $data;
    }

    /**
     * Convert a milliseconds into a sec+usec array
     */
    private function millisecToArray(int $milliseconds): array
    {
        $usec = $milliseconds * 1000;
        return ['sec' => (int)floor($usec / 1000000), 'usec' => $usec % 1000000];
    }

    public function write(string $buffer, int $length): void
    {
        if (!is_resource($this->socket) || feof($this->socket)) {
            throw new SocketTransportException('Stream connection error');
        }

        $r = $length;
        $writeTimeout = $this->millisecToArray($this->config->getDefaultSendTimeout());

        stream_set_timeout($this->socket, $writeTimeout['sec'], $writeTimeout['usec']);
        stream_set_blocking($this->socket, true);

        while ($r > 0) {
            $wrote = fwrite($this->socket, $buffer, $r);

            if (false === $wrote) {
                throw new SocketTransportException("Stream fail to write $length bytes");
            }

            $r -= $wrote;
            
            if (0 === $r) {
                return;
            }

            $buffer = substr($buffer, $wrote);
        }
    }
}
