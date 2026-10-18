<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Instance;

use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tells who this system is when it exports and when it recognises its own packages on import.
 *
 * The id is deliberately not stored in the database: copies of a database (local to production, production to
 * staging) would carry the same id. It derives from kernel.secret instead, which differs between systems.
 */
class InstanceIdentity
{
    private const ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public function __construct(
        private readonly string $secret,
        private readonly ?string $configuredId = null,
        private readonly ?string $configuredLabel = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function getId(): string
    {
        $configured = null !== $this->configuredId ? trim($this->configuredId) : '';
        if ('' === $configured) {
            return substr(hash_hmac('sha256', 'sulu-content-transfer:instance', $this->secret), 0, 16);
        }

        if (1 !== preg_match(self::ID_PATTERN, $configured)) {
            throw new \InvalidArgumentException('The configured sulu_content_transfer.instance_id may only contain letters, digits, ".", "_" and "-" (up to 64 characters).');
        }

        return $configured;
    }

    public function getLabel(): string
    {
        $configured = null !== $this->configuredLabel ? trim($this->configuredLabel) : '';
        if ('' !== $configured) {
            return $configured;
        }

        try {
            $host = $this->requestStack?->getMainRequest()?->getHost();
        } catch (SuspiciousOperationException) {
            $host = null; // untrusted host header: fall back instead of failing a console or export run
        }

        return null !== $host && '' !== $host ? $host : 'cli';
    }
}
