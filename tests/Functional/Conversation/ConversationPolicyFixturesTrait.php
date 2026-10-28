<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Entity\E2eeAccount;
use App\Entity\E2eeDevice;
use App\Entity\E2eeKeyPackage;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Settings\Settings;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

trait ConversationPolicyFixturesTrait
{
    /** @return list<User> */
    private function usersSharingCommunity(int $count): array
    {
        $community = CommunityFactory::createOne();
        $users = [];
        for ($i = 0; $i < $count; ++$i) {
            $user = UserFactory::createOne();
            CommunityMemberFactory::createForUserAndCommunity($user, $community);
            $users[] = $user;
        }

        return $users;
    }

    private function reachable(User ...$users): void
    {
        foreach ($users as $user) {
            $device = $this->device($user);
            $this->em()->persist(new E2eeKeyPackage($device, base64_encode(random_bytes(64)), new \DateTimeImmutable('+30 days')));
        }
        $this->em()->flush();
    }

    private function deviceWithoutUsablePackages(User $user, bool $expired = false, bool $revoked = false): void
    {
        $device = $this->device($user);
        if ($expired) {
            $this->em()->persist(new E2eeKeyPackage($device, base64_encode(random_bytes(64)), new \DateTimeImmutable('-1 day')));
        }
        if ($revoked) {
            $this->em()->persist(new E2eeKeyPackage($device, base64_encode(random_bytes(64)), new \DateTimeImmutable('+30 days')));
            $device->revoke(['signerDeviceId' => $device->getId(), 'revokedAt' => time(), 'signature' => '']);
        }
        $this->em()->flush();
    }

    private function refuses(User ...$users): void
    {
        foreach ($users as $user) {
            $pref = $this->em()->getRepository(UserPreference::class)->findOneBy(['user' => $user->getId()])
                ?? new UserPreference($this->em()->getReference(User::class, $user->getId()));
            $this->em()->persist($pref->setRefusePlainDms(true));
        }
        $this->em()->flush();
    }

    private function storeMode(string $mode): void
    {
        $this->em()->clear();
        $setting = $this->em()->find(Setting::class, Settings::encryptedDmsMode()->key) ?? new Setting(Settings::encryptedDmsMode()->key);
        $setting->setValue($mode);
        $this->em()->persist($setting);
        $this->em()->flush();
        $this->em()->clear();
    }

    private function device(User $user): E2eeDevice
    {
        $owner = $this->em()->getReference(User::class, $user->getId());
        $account = $this->em()->getRepository(E2eeAccount::class)->findOneBy(['user' => $user->getId()])
            ?? new E2eeAccount($owner, base64_encode(random_bytes(32)));
        $this->em()->persist($account);
        $device = new E2eeDevice(strtolower((string) Uuid::v4()), $account, 'Laptop', base64_encode(random_bytes(32)), 'https://localhost', $account->getGeneration(), base64_encode(random_bytes(64)));
        $this->em()->persist($device);

        return $device;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
