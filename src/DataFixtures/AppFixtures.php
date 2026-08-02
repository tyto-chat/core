<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Dto\User\CreateUserDto;
use App\Entity\Setting;
use App\Service\User\UserServiceInterface;
use App\Settings\Settings;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserServiceInterface $userService,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        // E2E registers via the real endpoint and can't read emailed challenge tokens — the gate must stay off.
        $manager->persist((new Setting(Settings::validateEmails()->key))->setValue(false));

        $manager->persist(
            (new Setting(Settings::adminOnboardedAt()->key))
                ->setValue(Settings::adminOnboardedAt()->type->encode(new \DateTimeImmutable())),
        );

        $this->userService->new(new CreateUserDto(
            email: 'admin@tyto.test',
            plainPassword: 'e2e-password',
            displayName: 'Admin',
        ), ['ROLE_ADMIN']);

        $manager->flush();
    }
}
