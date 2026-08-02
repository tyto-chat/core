<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\User\ChangePasswordDto;
use App\Dto\User\CreateUserDto;
use App\Entity\MediaObject;
use App\Entity\User;
use App\Exception\User\EmailAlreadyInUseException;
use App\Exception\User\RegistrationDisabledException;
use App\Exception\User\UserNotFoundException;

interface UserServiceInterface
{
    public function find(int $id): ?User;

    public function findByEmail(string $email): ?User;

    public function existsByEmail(string $email): bool;

    /**
     * @throws UserNotFoundException
     */
    public function get(int $id): User;

    /**
     * @param int[] $ids
     *
     * @return User[]
     */
    public function findByIds(array $ids): array;

    /**
     * @return User[]
     */
    public function getAdmins(): array;

    /**
     * @param list<string> $roles granted only by trusted callers (CLI, fixtures) — never from request input
     */
    public function new(CreateUserDto $createUserDto, array $roles = []): User;

    /**
     * @throws RegistrationDisabledException
     * @throws EmailAlreadyInUseException
     */
    public function register(CreateUserDto $dto): User;

    /**
     * @param iterable<CreateUserDto>            $dtos
     * @param ?callable(int $createdSoFar): void $onProgress invoked once per batch boundary
     */
    public function createBatch(iterable $dtos, int $batchSize = 100, ?callable $onProgress = null): void;

    public function createInvited(string $name, string $email): User;

    public function newBot(string $displayName): User;

    public function updatePassword(User $user, string $newPassword): void;

    public function changePassword(ChangePasswordDto $dto): void;

    /**
     * @param list<string> $roles
     */
    public function updateRoles(User $user, array $roles): User;

    /** @internal CLI-trusted, no authz — console rescue path like TwoFactorService::reset */
    public function grantAdminRole(User $user): User;

    public function adminSetDisplayName(User $user, string $displayName): User;

    public function setAvatar(MediaObject $avatar): void;

    public function setEmailNotifications(bool $enabled, ?string $locale = null): User;

    public function markOnboardedForCurrentUser(): User;

    /**
     * @return list<array{id: int, name: ?string, avatar: MediaObject|null}>
     */
    public function findInvitableForCurrentUser(?string $search, int $limit): array;
}
