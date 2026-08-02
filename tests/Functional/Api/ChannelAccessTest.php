<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Channel\ChannelRole;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChannelAccessTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function minimalPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    private function createUploadedFile(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ch_access_test_');
        file_put_contents((string) $tmp, $this->minimalPng());

        return new UploadedFile((string) $tmp, 'test.png', 'image/png', null, true);
    }

    public function testRegularMemberCannotPostInReadonlyChannel(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('readonly-deny')->create();
        $channel = ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'announce', 'readonly' => true])
            ->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        ChannelMemberFactory::createForUserAndChannel($member, $channel);

        $this->jsonClient($member)->request(
            'POST',
            '/api/v1/communities/readonly-deny/channels/announce/messages',
            ['json' => ['text' => 'Can I post here?']],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testChannelModeratorCanPostInReadonlyChannel(): void
    {
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('readonly-mod-ok')->create();
        $channel = ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'announce', 'readonly' => true])
            ->create();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);

        $this->jsonClient($mod)->request(
            'POST',
            '/api/v1/communities/readonly-mod-ok/channels/announce/messages',
            ['json' => ['text' => 'Announcement!']],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testCommunityModeratorCanPostInReadonlyChannel(): void
    {
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('readonly-communitymod-ok')->create();
        ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'announce', 'readonly' => true])
            ->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);

        $this->jsonClient($mod)->request(
            'POST',
            '/api/v1/communities/readonly-communitymod-ok/channels/announce/messages',
            ['json' => ['text' => 'Announcement!']],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testCannotUploadAttachmentToChannelWithAttachmentsDisabled(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('no-attach')->create();
        $channel = ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'locked-ch', 'allowAttachments' => false])
            ->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        ChannelMemberFactory::createForUserAndChannel($member, $channel);

        $this->uploadClient($member)->request(
            'POST',
            '/api/v1/communities/no-attach/channels/locked-ch/attachments',
            ['extra' => ['files' => ['file' => $this->createUploadedFile()]]],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testCanUploadAttachmentToChannelWithAttachmentsEnabled(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('yes-attach')->create();
        $channel = ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'open-ch', 'allowAttachments' => true])
            ->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        ChannelMemberFactory::createForUserAndChannel($member, $channel);

        $this->uploadClient($member)->request(
            'POST',
            '/api/v1/communities/yes-attach/channels/open-ch/attachments',
            ['extra' => ['files' => ['file' => $this->createUploadedFile()]]],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testNonMemberCannotReadMessagesInPrivateChannel(): void
    {
        $member = UserFactory::createOne();
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('priv-ch-read')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'secret'])->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        ChannelMemberFactory::createForUserAndChannel($member, $channel);

        $this->jsonClient($outsider)->request(
            'GET',
            '/api/v1/communities/priv-ch-read/channels/secret/messages/current',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonMemberCannotReadPublicChannelInPrivateCommunity(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('priv-com-pub-ch')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->jsonClient($outsider)->request(
            'GET',
            '/api/v1/communities/priv-com-pub-ch/channels/general/messages/current',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testMemberCanReadPublicChannelInPrivateCommunity(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('priv-com-pub-ch-ok')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        MessagePageFactory::new()->forChannel($channel)->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->jsonClient($member)->request(
            'GET',
            '/api/v1/communities/priv-com-pub-ch-ok/channels/general/messages/current',
        );

        self::assertResponseIsSuccessful();
    }

    public function testNonMemberCannotUploadToPrivateChannel(): void
    {
        $member = UserFactory::createOne();
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('priv-ch-upload')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'secret'])->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        ChannelMemberFactory::createForUserAndChannel($member, $channel);

        $this->uploadClient($outsider)->request(
            'POST',
            '/api/v1/communities/priv-ch-upload/channels/secret/attachments',
            ['extra' => ['files' => ['file' => $this->createUploadedFile()]]],
        );

        self::assertResponseStatusCodeSame(404);
    }
}
