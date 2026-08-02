<?php

declare(strict_types=1);

namespace App\Dto\Profile;

use App\Dto\EntityDtoInterface;
use App\Dto\PatchDtoTrait;
use App\Entity\MediaObject;
use App\Entity\Profile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UpdateProfileDto implements EntityDtoInterface
{
    use PatchDtoTrait;

    #[Assert\Length(min: 4, max: 255)]
    public string $name;

    public ?MediaObject $avatar;

    #[Assert\Length(max: 190)]
    public ?string $bio;

    #[Assert\Length(max: 100)]
    public ?string $location;

    #[Assert\Length(max: 255)]
    #[Assert\Url(requireTld: true)]
    public ?string $website;

    #[Assert\Range(min: 1, max: 12)]
    public ?int $birthdayMonth;

    #[Assert\Range(min: 1, max: 31)]
    public ?int $birthdayDay;

    public static function getEntityClass(): string
    {
        return Profile::class;
    }

    #[Assert\Callback]
    public function validateBirthday(ExecutionContextInterface $context): void
    {
        $hasMonth = $this->isProvided('birthdayMonth')
            && null !== $this->birthdayMonth;
        $hasDay = $this->isProvided('birthdayDay')
            && null !== $this->birthdayDay;
        if ($hasMonth !== $hasDay) {
            $context->buildViolation('Birthday month and day must be provided together.')
                ->atPath('birthdayDay')
                ->addViolation();
        }
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Profile);
        if ($this->isProvided('name')) {
            $entity->setName($this->name);
        }
        if ($this->isProvided('avatar')) {
            $entity->setAvatar($this->avatar);
        }
        if ($this->isProvided('bio')) {
            $entity->setBio('' === $this->bio ? null : $this->bio);
        }
        if ($this->isProvided('location')) {
            $entity->setLocation('' === $this->location ? null : $this->location);
        }
        if ($this->isProvided('website')) {
            $entity->setWebsite('' === $this->website ? null : $this->website);
        }
        if ($this->isProvided('birthdayMonth')) {
            $entity->setBirthdayMonth($this->birthdayMonth);
        }
        if ($this->isProvided('birthdayDay')) {
            $entity->setBirthdayDay($this->birthdayDay);
        }
    }
}
