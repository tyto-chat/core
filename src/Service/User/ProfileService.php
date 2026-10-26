<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\Profile\UpdateProfileDto;
use App\Entity\MediaObject;
use App\Entity\Profile;
use App\Exception\Profile\InvalidAvatarException;
use App\Exception\Profile\ProfileNotFoundException;
use App\Repository\ProfileRepository;
use App\Security\SecurityContext;
use App\Security\Voter\MediaObjectVoter;
use App\Security\Voter\ProfileVoter;
use App\Service\AbstractDoctrineService;
use App\Service\HttpCache\PurgesHttpCacheTrait;

class ProfileService extends AbstractDoctrineService implements ProfileServiceInterface
{
    use PurgesHttpCacheTrait;

    public function __construct(
        private readonly SecurityContext $security,
        private readonly ProfileRepository $profileRepository,
    ) {
    }

    #[\Override]
    public function get(int $id): Profile
    {
        return $this->getByCriteria(['id' => $id]);
    }

    #[\Override]
    public function findByAvatar(MediaObject $avatar): ?Profile
    {
        return $this->profileRepository->findByAvatar($avatar);
    }

    #[\Override]
    public function update(Profile $profile, UpdateProfileDto $updateProfileDto): Profile
    {
        $this->security->throwAccessDeniedUnlessGranted(ProfileVoter::UPDATE, $profile, 'You do not have permission to update this profile.');

        $avatar = $updateProfileDto->isProvided('avatar') ? $updateProfileDto->avatar : null;
        if (null !== $avatar) {
            if ('avatar' !== $avatar->type) {
                throw new InvalidAvatarException('The referenced media object is not an avatar.');
            }
            $inUseBy = $this->profileRepository->findByAvatar($avatar);
            if (!$this->security->isGranted(MediaObjectVoter::LINK, $avatar) || (null !== $inUseBy && $inUseBy !== $profile)) {
                throw new InvalidAvatarException('The referenced media object is not your avatar.');
            }
        }

        $saved = $this->save($profile, $updateProfileDto);
        $this->cachePurger?->purgeAuthor((int) $profile->getUser()?->getId());

        return $saved;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function getByCriteria(array $criteria): Profile
    {
        $profile = $this->profileRepository->findOneBy($criteria);
        if (!$profile) {
            $logMessage = sprintf('Profile not found using criteria: %s', json_encode($criteria, \JSON_THROW_ON_ERROR));
            $this->logger->warning($logMessage);

            throw new ProfileNotFoundException($logMessage);
        }

        $this->security->throwAccessDeniedUnlessGranted(ProfileVoter::VIEW, $profile, 'You do not have access to this profile.');

        return $profile;
    }
}
