<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ResetUserTwoFactorCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private function commandTester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('tyto:user:reset-2fa'));
    }

    public function testResetsEnrolledUser(): void
    {
        $user = UserFactory::new()->withTwoFactor()->create(['email' => 'locked@example.com']);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['email' => 'locked@example.com']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Two-factor authentication reset', $tester->getDisplay());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(User::class)->findOneBy(['email' => 'locked@example.com']);
        self::assertNotNull($fresh);
        self::assertNull($fresh->getTwoFactorSecret());
        self::assertNull($fresh->getTotpEnabledAt());

        $connection = $em->getConnection();
        $codes = (int) $connection->fetchOne('SELECT COUNT(*) FROM two_factor_recovery_code');
        self::assertSame(0, $codes);
        $audits = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM admin_audit_log WHERE action = 'user.two_factor_disable'",
        );
        self::assertSame(1, $audits);
    }

    public function testReportsWhenTwoFactorNotEnabled(): void
    {
        UserFactory::createOne(['email' => 'plain@example.com']);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['email' => 'plain@example.com']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('not enabled', $tester->getDisplay());
    }

    public function testFailsOnUnknownEmail(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute(['email' => 'ghost@example.com']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No user found', $tester->getDisplay());
    }
}
