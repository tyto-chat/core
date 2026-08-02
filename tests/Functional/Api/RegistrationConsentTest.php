<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Setting;
use App\Entity\User;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Trait\DisablesEmailValidationTrait;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class RegistrationConsentTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;
    use DisablesEmailValidationTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEmailValidationDisabled();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function requireConsent(): void
    {
        $this->setSetting('requireRegistrationConsent', true);
    }

    private function setSetting(string $key, mixed $value): void
    {
        $em = $this->em();
        $setting = new Setting($key);
        $setting->setValue($value);
        $em->persist($setting);
        $em->flush();
        $em->clear();
    }

    public function testRegistrationBlockedWithoutConsentWhenPolicyConfigured(): void
    {
        $this->requireConsent();

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'noconsent@example.com',
            'password' => 'securepass',
            'displayName' => 'No Consent',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegistrationSucceedsWithConsentAndStampsTimestamp(): void
    {
        $this->requireConsent();

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'consent@example.com',
            'password' => 'securepass',
            'displayName' => 'With Consent',
            'acceptedTerms' => true,
        ]]);

        self::assertResponseStatusCodeSame(201);

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'consent@example.com']);
        self::assertNotNull($user);
        self::assertNotNull($user->getTermsAcceptedAt());
    }

    public function testRegistrationUnaffectedWhenNoPolicyConfigured(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'nopolicy@example.com',
            'password' => 'securepass',
            'displayName' => 'No Policy',
        ]]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testUnderageRegistrationBlocked(): void
    {
        $this->setSetting('minimumAgeYears', 16);
        $recentDob = (new \DateTimeImmutable('-10 years'))->format('Y-m-d');

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'kid@example.com',
            'password' => 'securepass',
            'displayName' => 'Too Young',
            'dateOfBirth' => $recentDob,
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testMissingDobBlockedWhenAgeGateOn(): void
    {
        $this->setSetting('minimumAgeYears', 16);

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'nodob@example.com',
            'password' => 'securepass',
            'displayName' => 'No DOB',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testOldEnoughRegistrationSucceedsAndStampsVerification(): void
    {
        $this->setSetting('minimumAgeYears', 16);
        $adultDob = (new \DateTimeImmutable('-30 years'))->format('Y-m-d');

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'adult@example.com',
            'password' => 'securepass',
            'displayName' => 'Old Enough',
            'dateOfBirth' => $adultDob,
        ]]);

        self::assertResponseStatusCodeSame(201);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'adult@example.com']);
        self::assertNotNull($user);
        self::assertNotNull($user->getAgeVerifiedAt());
    }
}
