<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

use Doctrine\ORM\EntityManagerInterface;
use Manuxi\SuluContentTransferBundle\Exception\ContentTransferException;
use Sulu\Component\Security\Authentication\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Puts the user into the security token, the way a login does, so Sulu records them as creator and changer.
 */
class SecurityImportUserContext implements ImportUserContext
{
    private const FIREWALL = 'admin';

    private ?int $userId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function activate(string $username): void
    {
        $user = $this->entityManager->getRepository(UserInterface::class)->findOneBy(['username' => $username]);
        if (!$user instanceof UserInterface) {
            throw new ContentTransferException(\sprintf('There is no user "%s".', $username), 'import.user_unknown');
        }
        if (!$user->getEnabled()) {
            throw new ContentTransferException(\sprintf('The user "%s" is disabled.', $username), 'import.user_disabled');
        }

        $this->userId = $user->getId();
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, self::FIREWALL, $user->getRoles()));
    }

    public function refresh(): void
    {
        if (null === $this->userId) {
            throw new \LogicException('No user was activated.');
        }

        // clear() detaches the user the token holds; without a fresh one the next flush fails ("Unable to find User entity identifier")
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(UserInterface::class)->find($this->userId);
        if (!$user instanceof UserInterface) {
            throw new ContentTransferException('The user of the import no longer exists.', 'import.user_unknown');
        }
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, self::FIREWALL, $user->getRoles()));
    }

    public function userId(): int
    {
        if (null === $this->userId) {
            throw new \LogicException('No user was activated.');
        }

        return $this->userId;
    }
}
