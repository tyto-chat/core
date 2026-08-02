<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailer;

use App\Enum\Settings\SmtpEncryption;
use App\Mailer\DbConfiguredTransport;
use App\Service\Security\SecretBoxInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\SettingDef;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class DbConfiguredTransportTest extends TestCase
{
    public function testDelegatesToInnerWhenDbSmtpUnset(): void
    {
        $svc = self::createStub(SettingsServiceInterface::class);
        $svc->method('isSmtpConfigured')->willReturn(false);
        $secret = self::createStub(SecretBoxInterface::class);

        $inner = $this->createMock(TransportInterface::class);
        $inner->expects(self::once())->method('send');

        $t = new DbConfiguredTransport($inner, $svc, $secret);
        $t->send(new RawMessage('x'));
    }

    public function testBuildsEsmtpWhenDbSmtpSet(): void
    {
        $svc = self::createStub(SettingsServiceInterface::class);
        $svc->method('isSmtpConfigured')->willReturn(true);
        $svc->method('lastUpdate')->willReturn(['at' => new \DateTimeImmutable('2024-01-01'), 'by' => null]);
        $svc->method('get')->willReturnCallback(static function (SettingDef $def) {
            return match ($def->key) {
                'smtpEncryption' => SmtpEncryption::Tls,
                'smtpHost' => 'smtp.example.com',
                'smtpPort' => 587,
                'smtpUsername' => 'u',
                'smtpPassword' => 'enc',
                default => null,
            };
        });
        $secret = self::createStub(SecretBoxInterface::class);
        $secret->method('decrypt')->willReturn('pw');

        $inner = $this->createMock(TransportInterface::class);
        $inner->expects(self::never())->method('send');

        $t = new DbConfiguredTransport($inner, $svc, $secret);
        $built = $t->buildTransport();
        self::assertInstanceOf(EsmtpTransport::class, $built);
    }

    public function testBuiltTransportCarriesEventDispatcher(): void
    {
        $svc = self::createStub(SettingsServiceInterface::class);
        $svc->method('isSmtpConfigured')->willReturn(true);
        $svc->method('lastUpdate')->willReturn(['at' => new \DateTimeImmutable('2024-01-01'), 'by' => null]);
        $svc->method('get')->willReturnCallback(static function (SettingDef $def) {
            return match ($def->key) {
                'smtpHost' => 'smtp.example.com',
                default => null,
            };
        });

        $dispatcher = self::createStub(EventDispatcherInterface::class);
        $t = new DbConfiguredTransport(
            self::createStub(TransportInterface::class),
            $svc,
            self::createStub(SecretBoxInterface::class),
            $dispatcher,
        );

        $built = $t->buildTransport();
        self::assertNotNull($built);

        // With async Mailer, From/envelope stamping happens via the MessageEvent
        // AbstractTransport::send dispatches — a dispatcherless transport sends
        // From-less mail that SMTP servers reject with "must have a From header".
        $prop = new \ReflectionProperty(AbstractTransport::class, 'dispatcher');
        self::assertSame($dispatcher, $prop->getValue($built));
    }

    public function testReturnsNullBuildWhenUnset(): void
    {
        $svc = self::createStub(SettingsServiceInterface::class);
        $svc->method('isSmtpConfigured')->willReturn(false);
        $t = new DbConfiguredTransport(
            self::createStub(TransportInterface::class),
            $svc,
            self::createStub(SecretBoxInterface::class),
        );
        self::assertNull($t->buildTransport());
    }
}
