<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\ChannelSection;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChannelSectionTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function createSection(\App\Entity\Community $community, string $name = 'Text Channels'): ChannelSection
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $section = new ChannelSection();
        $section->setName($name);
        $section->setCommunity($community);
        $em->persist($section);
        $em->flush();

        return $section;
    }

    public function testAdminCanCreateSection(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('sec-c')->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/sec-c/sections', ['json' => [
            'name' => 'Voice Channels',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['name' => 'Voice Channels']);
    }

    public function testRegularUserCannotCreateSection(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('sec-c2')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/sec-c2/sections', ['json' => [
            'name' => 'Hacked Section',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testMemberCanGetSection(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('sec-get')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $section = $this->createSection($community);

        $this->jsonClient($user)->request('GET', '/api/v1/communities/sec-get/sections/'.$section->getId());

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Text Channels']);
    }

    public function testAdminCanUpdateSection(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('sec-upd')->create();
        $section = $this->createSection($community);

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/sec-upd/sections/'.$section->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Renamed Section'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Renamed Section']);
    }

    public function testRegularUserCannotUpdateSection(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('sec-upd2')->create();
        $section = $this->createSection($community);

        $this->jsonClient($user)->request('PATCH', '/api/v1/communities/sec-upd2/sections/'.$section->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hacked'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanDeleteEmptySection(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('sec-del')->create();
        $section = $this->createSection($community);

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/sec-del/sections/'.$section->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testCannotDeleteSectionWithChannels(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('sec-del2')->create();
        $section = $this->createSection($community, 'Has Channels');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $channel = new \App\Entity\Channel();
        $channel->setName('general');
        $channel->setIdentifier('general');
        $channel->setCommunity($community);
        $section->addChannel($channel);
        $em->persist($channel);
        $em->flush();

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/sec-del2/sections/'.$section->getId());

        self::assertResponseStatusCodeSame(422);
    }

    public function testSectionWithNoVisibleChannelsIsHiddenFromNonAdmins(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $member = UserFactory::createOne();
        $grantHolder = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('sec-vis')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($grantHolder, $community);

        $publicSection = $this->createSection($community, 'Public stuff');
        $privateSection = $this->createSection($community, 'Secret stuff');
        $emptySection = $this->createSection($community, 'Empty');

        $publicChannel = \App\Tests\Factory\ChannelFactory::new()
            ->inCommunity($community)->with(['identifier' => 'sec-vis-pub'])->create();
        $privateChannel = \App\Tests\Factory\ChannelFactory::new()
            ->inCommunity($community)->private()->with(['identifier' => 'sec-vis-priv'])->create();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $publicChannel->setSection($publicSection);
        $privateChannel->setSection($privateSection);
        $em->flush();

        \App\Tests\Factory\ChannelMemberFactory::createForUserAndChannel(
            $grantHolder,
            $privateChannel,
            \App\Enum\Channel\ChannelRole::Member,
        );

        $path = '/api/v1/communities/sec-vis';

        $memberSections = array_column(
            $this->jsonClient($member)->request('GET', $path)->toArray()['channelSections'],
            'id',
        );
        self::assertContains($publicSection->getId(), $memberSections);
        self::assertNotContains($privateSection->getId(), $memberSections, 'a section whose only channel is invisible must not leak to plain members');
        self::assertNotContains($emptySection->getId(), $memberSections, 'a channel-less section must not be listed for plain members');

        $grantSections = array_column(
            $this->jsonClient($grantHolder)->request('GET', $path)->toArray()['channelSections'],
            'id',
        );
        self::assertContains($privateSection->getId(), $grantSections, 'a private-channel grant-holder must see the section holding their channel');

        $adminSections = array_column(
            $this->jsonClient($admin)->request('GET', $path)->toArray()['channelSections'],
            'id',
        );
        self::assertContains($emptySection->getId(), $adminSections, 'admins keep empty sections (they need them to add channels into)');
        self::assertContains($privateSection->getId(), $adminSections);
    }
}
