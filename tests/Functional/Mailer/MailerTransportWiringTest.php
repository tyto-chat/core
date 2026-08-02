<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mailer;

use App\Mailer\DbConfiguredTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards the decoration target. Mailer, the messenger send handler, and
 * mailer:test all inject `mailer.transports`; decorating any other mailer
 * service (e.g. the `mailer.default_transport` alias, the original bug)
 * compiles fine but leaves every real send on the raw env transport and
 * admin-configured DB SMTP silently ignored.
 */
final class MailerTransportWiringTest extends KernelTestCase
{
    public function testMailerTransportsIsDecoratedByDbConfiguredTransport(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            DbConfiguredTransport::class,
            self::getContainer()->get('mailer.transports'),
        );
    }
}
