<?php
declare(strict_types=1);
namespace Weaver\ORM\Bridge\Symfony\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Weaver\ORM\Repository\AbstractRepository;

final class EntityUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly AbstractRepository $repository,
        private readonly string $usernameField,
    ) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->repository->findOneBy([$this->usernameField => $identifier]);
        if ($user === null) {
            $ex = new UserNotFoundException("User '{$identifier}' not found.");
            $ex->setUserIdentifier($identifier);
            throw $ex;
        }
        if (!$user instanceof UserInterface) {
            throw new UnsupportedUserException(sprintf(
                'Entity %s must implement %s.', get_class($user), UserInterface::class,
            ));
        }
        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$this->supportsClass(get_class($user))) {
            throw new UnsupportedUserException(sprintf('Unsupported user class: %s', get_class($user)));
        }
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        $entityClass = $this->repository->getEntityClass();
        return $class === $entityClass || is_subclass_of($class, $entityClass);
    }
}
