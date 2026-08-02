<?php

declare(strict_types=1);

namespace App\Tests\Functional\User;

use App\Dto\Admin\ServerConfigPatchDto;
use App\Entity\Challenge;
use App\Entity\User;
use App\Enum\IpReputation\IpReputationVerdict;
use App\Service\IpReputation\IpReputationServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\InMemoryIpReputationService;
use App\Tests\Trait\DisablesEmailValidationTrait;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RegistrationIpReputationTest extends ApiTestCase
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

    private function stub(): InMemoryIpReputationService
    {
        $stub = static::getContainer()->get(IpReputationServiceInterface::class);
        \assert($stub instanceof InMemoryIpReputationService);

        return $stub;
    }

    private function setAppealContact(string $contact): void
    {
        $settings = static::getContainer()->get(SettingsServiceInterface::class);
        \assert($settings instanceof SettingsServiceInterface);

        $dto = new ServerConfigPatchDto();
        $dto->ipReputationAppealContact = $contact;
        $settings->applyPatch($dto);
    }

    private function userCount(): int
    {
        return $this->em()->getRepository(User::class)->count([]);
    }

    public function testFlaggedVerdictBlocksRegistrationWithoutContact(): void
    {
        $this->stub()->verdict = IpReputationVerdict::Flagged;

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'flagged@example.com',
            'password' => 'securepass',
            'displayName' => 'Flagged User',
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['error' => 'Registration could not be completed.']);
    }

    public function testFlaggedVerdictBlocksRegistrationWithContact(): void
    {
        $this->setAppealContact('admin@example.com');
        $this->stub()->verdict = IpReputationVerdict::Flagged;

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'flagged-contact@example.com',
            'password' => 'securepass',
            'displayName' => 'Flagged User',
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['error' => 'Registration could not be completed. If you believe this is an error, contact admin@example.com.']);
    }

    public function testUnavailableVerdictFailsOpenAndSucceeds(): void
    {
        $this->stub()->verdict = IpReputationVerdict::Unavailable;

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'unavailable@example.com',
            'password' => 'securepass',
            'displayName' => 'Unavailable Check',
        ]]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testCleanVerdictSucceedsAndRunsCheck(): void
    {
        $this->stub()->verdict = IpReputationVerdict::Clean;

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'clean@example.com',
            'password' => 'securepass',
            'displayName' => 'Clean Check',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertNotNull($this->stub()->lastCheck);
        self::assertSame('clean@example.com', $this->stub()->lastCheck[1]);
    }

    public function testFlaggedRegistrantConsumesNoChallengeAndPersistsNoUser(): void
    {
        $this->stub()->verdict = IpReputationVerdict::Flagged;

        $challenge = new Challenge();
        $challenge->setEmail('flagged-challenge@example.com');
        $challenge->setExpiresAt(new \DateTimeImmutable('+10 minutes'));
        $this->em()->persist($challenge);
        $this->em()->flush();
        $token = $challenge->getPlainToken();

        $userCountBefore = $this->userCount();

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'flagged-challenge@example.com',
            'password' => 'securepass',
            'displayName' => 'Flagged Challenge User',
            'challengeToken' => $token,
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($userCountBefore, $this->userCount());

        $this->em()->clear();
        $refreshedChallenge = $this->em()->getRepository(Challenge::class)->find($challenge->getId());
        self::assertNotNull($refreshedChallenge);
        self::assertNull($refreshedChallenge->getUsedAt());
    }
}
