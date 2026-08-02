<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Setting;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class LegalDocumentTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    public function testDefaultTermsServedAnonymously(): void
    {
        $body = static::createClient()->request('GET', '/api/v1/legal/terms', ['headers' => ['Accept' => 'application/json']])->toArray();

        self::assertSame('terms', $body['type']);
        self::assertStringContainsString('Terms of Service', $body['content']);
        self::assertFalse($body['customized']);
    }

    public function testDefaultPrivacyServedAnonymously(): void
    {
        $body = static::createClient()->request('GET', '/api/v1/legal/privacy', ['headers' => ['Accept' => 'application/json']])->toArray();

        self::assertSame('privacy', $body['type']);
        self::assertStringContainsString('Privacy Policy', $body['content']);
    }

    public function testUnknownDocumentIs404(): void
    {
        static::createClient()->request('GET', '/api/v1/legal/cookies', ['headers' => ['Accept' => 'application/json']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOverrideIsServedAndMarkedCustomized(): void
    {
        $em = $this->em();
        $setting = new Setting('termsContent');
        $setting->setValue('# Custom terms for %server_name%');
        $em->persist($setting);
        $em->flush();
        $em->clear();

        $body = static::createClient()->request('GET', '/api/v1/legal/terms', ['headers' => ['Accept' => 'application/json']])->toArray();

        self::assertStringContainsString('Custom terms for', $body['content']);
        self::assertStringNotContainsString('%server_name%', $body['content']);
        self::assertTrue($body['customized']);
    }

    public function testDefaultVariantIgnoresOverride(): void
    {
        $em = $this->em();
        $setting = new Setting('termsContent');
        $setting->setValue('# Custom override');
        $em->persist($setting);
        $em->flush();
        $em->clear();

        $body = static::createClient()->request('GET', '/api/v1/legal/terms?variant=default', ['headers' => ['Accept' => 'application/json']])->toArray();

        self::assertStringContainsString('Terms of Service', $body['content']);
        self::assertStringNotContainsString('Custom override', $body['content']);
    }
}
