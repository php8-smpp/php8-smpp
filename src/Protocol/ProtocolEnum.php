<?php

declare(strict_types=1);

namespace Smpp\Protocol;

enum ProtocolEnum: string
{
    case SSL = 'ssl';
    case TCP = 'tcp';
    case TCP4 = 'tcp4';
    case TCP6 = 'tcp6';
    case TLS = 'tls';
}
