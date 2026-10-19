<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Target;

use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * Webspaces and languages from the webspace configuration of Sulu.
 */
class WebspaceSystemTarget implements SystemTarget
{
    private readonly ?string $defaultWebspace;

    /**
     * @param mixed $defaultMainWebspace value of the parameter "sulu_article.default_main_webspace": a key, a list of
     *                                   keys or a map with the key "default"
     */
    public function __construct(
        private readonly WebspaceManagerInterface $webspaceManager,
        mixed $defaultMainWebspace = null,
    ) {
        $this->defaultWebspace = self::firstKey($defaultMainWebspace);
    }

    public function webspaceKeys(): array
    {
        $keys = [];
        foreach ($this->webspaceManager->getWebspaceCollection() as $webspace) {
            $keys[] = (string) $webspace->getKey();
        }

        return $keys;
    }

    public function localesOf(string $webspace): array
    {
        return array_map('strval', array_keys($this->webspaceManager->getAllLocalesByWebspaces()[$webspace] ?? []));
    }

    public function defaultWebspace(): ?string
    {
        if (null !== $this->defaultWebspace) {
            return $this->defaultWebspace;
        }

        $keys = $this->webspaceKeys();

        return 1 === \count($keys) ? $keys[0] : null;
    }

    private static function firstKey(mixed $value): ?string
    {
        if (\is_string($value) && '' !== $value) {
            return $value;
        }
        if (\is_array($value)) {
            foreach ($value as $item) {
                $key = self::firstKey($item);
                if (null !== $key) {
                    return $key;
                }
            }
        }

        return null;
    }
}
