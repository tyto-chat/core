<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\ChannelSection;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChannelOrderingTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testNewSectionAppendsAtHighestPosition(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ord-sec')->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/ord-sec/sections', ['json' => [
            'name' => 'First Section',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/ord-sec/sections', ['json' => [
            'name' => 'Second Section',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $first = $em->getRepository(ChannelSection::class)->findOneBy(['name' => 'First Section']);
        $second = $em->getRepository(ChannelSection::class)->findOneBy(['name' => 'Second Section']);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertGreaterThan($first->getPosition(), $second->getPosition());
    }

    public function testNewChannelAppendsAtHighestPositionInSection(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ord-ch')->create();

        $sectionResponse = $this->jsonClient($admin)->request('POST', '/api/v1/communities/ord-ch/sections', ['json' => [
            'name' => 'My Section',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $sectionData = $sectionResponse->toArray();
        $sectionIri = $sectionData['@id'];

        $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'First Channel',
            'type' => 'text',
            'community' => '/api/v1/communities/ord-ch',
            'section' => $sectionIri,
        ]]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Second Channel',
            'type' => 'text',
            'community' => '/api/v1/communities/ord-ch',
            'section' => $sectionIri,
        ]]);
        self::assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $first = $em->getRepository(Channel::class)->findOneBy(['name' => 'First Channel']);
        $second = $em->getRepository(Channel::class)->findOneBy(['name' => 'Second Channel']);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertGreaterThan($first->getPosition(), $second->getPosition());
    }
}
