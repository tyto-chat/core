<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\MediaObject;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class HomeTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testHomeRendersAndListsPublicCommunities(): void
    {
        CommunityFactory::new()->withIdentifier('home-public')->create();

        $response = static::createClient()->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = $response->getContent();
        self::assertStringContainsString('Public communities', $html);
        self::assertStringContainsString('home-public', $html);
    }

    public function testHomeRendersCommunityLogo(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $logo = new MediaObject();
        $logo->filePath = 'home-logo.png';
        $logo->type = 'logo';
        $em->persist($logo);
        $em->flush();

        CommunityFactory::createOne(['identifier' => 'home-logo', 'logo' => $logo]);

        $html = static::createClient()->request('GET', '/')->getContent();

        self::assertStringContainsString('logo_sm/home-logo.png', $html);
    }

    public function testHomeExcludesPrivateCommunities(): void
    {
        CommunityFactory::new()->private()->withIdentifier('home-private')->create();

        $html = static::createClient()->request('GET', '/')->getContent();

        self::assertStringNotContainsString('home-private', $html);
    }
}
