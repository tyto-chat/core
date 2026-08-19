<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\MediaObject;
use App\Entity\Setting;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AttachmentTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, \App\Entity\Community, \App\Entity\Channel} */
    private function setupCommunityAndChannel(bool $allowAttachments = true): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('att-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'att-ch', 'allowAttachments' => $allowAttachments])
            ->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        return [$user, $community, $channel];
    }

    private function minimalPng(): string
    {
        // 1x1 transparent PNG
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    private function createUploadedFile(
        string $mime = 'image/png',
        string $clientName = 'test.png',
        ?string $content = null,
    ): UploadedFile {
        $tmp = tempnam(sys_get_temp_dir(), 'att_test_');
        file_put_contents((string) $tmp, $content ?? $this->minimalPng());

        return new UploadedFile((string) $tmp, $clientName, $mime, null, true);
    }

    /**
     * Upload a file and return the IRI of the created MediaObject.
     */
    private function uploadAttachment(User $user, string $communityId = 'att-c', string $channelId = 'att-ch'): string
    {
        $file = $this->createUploadedFile();

        $response = $this->uploadClient($user)->request(
            'POST',
            "/api/v1/communities/{$communityId}/channels/{$channelId}/attachments",
            ['extra' => ['files' => ['file' => $file]]]
        );

        self::assertResponseStatusCodeSame(201);

        return (string) $response->toArray()['@id'];
    }

    public function testMemberCanUploadAttachment(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $file = $this->createUploadedFile('image/png', 'photo.png');

        $this->uploadClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains([
            'originalName' => 'photo.png',
            'mimeType' => 'image/png',
        ]);
    }

    public function testCommunityNonMemberCannotUploadAttachment(): void
    {
        $this->setupCommunityAndChannel();
        $outsider = UserFactory::createOne();

        $file = $this->createUploadedFile();

        $this->uploadClient($outsider)->request('POST', '/api/v1/communities/att-c/channels/att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUploadResponseContainsContentUrl(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $file = $this->createUploadedFile();

        $response = $this->uploadClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertArrayHasKey('contentUrl', $data);
        self::assertIsString($data['contentUrl']);
    }

    public function testAnonymousCannotUploadAttachment(): void
    {
        $community = CommunityFactory::new()->withIdentifier('anon-att')->create();
        ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'ch'])
            ->create();

        $file = $this->createUploadedFile();

        $this->uploadClient()->request('POST', '/api/v1/communities/anon-att/channels/ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUploadToChannelWithAttachmentsDisabledReturns403(): void
    {
        [$user] = $this->setupCommunityAndChannel(allowAttachments: false);

        $file = $this->createUploadedFile();

        $this->uploadClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUploadDisallowedMimeTypeReturns422(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        // ELF binary — finfo detects as application/x-executable (not in default allowed list)
        $tmp = tempnam(sys_get_temp_dir(), 'att_test_');
        file_put_contents((string) $tmp, "\x7fELF\x02\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00");
        $file = new UploadedFile((string) $tmp, 'malware.elf', 'application/x-executable', null, true);

        $this->uploadClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadToPrivateChannelAsNonMemberReturns404(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('priv-att-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'priv-ch'])->create();

        $file = $this->createUploadedFile();

        $this->uploadClient($outsider)->request('POST', '/api/v1/communities/priv-att-c/channels/priv-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSendMessageWithAttachment(): void
    {
        [$user] = $this->setupCommunityAndChannel();
        $iri = $this->uploadAttachment($user);

        $response = $this->jsonClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/messages', [
            'json' => ['text' => 'Here is a file', 'attachmentIris' => [$iri]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertCount(1, $data['attachments']);
        self::assertArrayHasKey('contentUrl', $data['attachments'][0]);
    }

    public function testSendMessageWithAnotherUsersAttachmentReturns403(): void
    {
        $owner = UserFactory::createOne();
        $thief = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('steal-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($thief, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'steal-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($owner, $channel);
        ChannelMemberFactory::createForUserAndChannel($thief, $channel);

        $file = $this->createUploadedFile();
        $response = $this->uploadClient($owner)->request('POST', '/api/v1/communities/steal-c/channels/steal-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $iri = $response->toArray()['@id'];

        // Thief tries to use the owner's attachment — blocked by MediaObjectVoter::LINK
        $this->jsonClient($thief)->request('POST', '/api/v1/communities/steal-c/channels/steal-ch/messages', [
            'json' => ['text' => 'Stolen!', 'attachmentIris' => [$iri]],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSendMessageWithAlreadyLinkedAttachmentReturns422(): void
    {
        [$user] = $this->setupCommunityAndChannel();
        $iri = $this->uploadAttachment($user);

        $this->jsonClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/messages', [
            'json' => ['text' => 'First use', 'attachmentIris' => [$iri]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/messages', [
            'json' => ['text' => 'Second use', 'attachmentIris' => [$iri]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testSendMessageExceedingMaxAttachmentsReturns422(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        // Upload more attachments than APP_ATTACHMENT_MAX_PER_MESSAGE (default 10)
        $iris = [];
        for ($i = 0; $i <= 10; ++$i) {
            $file = $this->createUploadedFile('image/png', "photo_{$i}.png");
            $response = $this->uploadClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/attachments', [
                'extra' => ['files' => ['file' => $file]],
            ]);
            self::assertResponseStatusCodeSame(201);
            $iris[] = $response->toArray()['@id'];
        }

        $this->jsonClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/messages', [
            'json' => ['text' => 'Too many', 'attachmentIris' => $iris],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAttachmentLimitSettingIsEnforced(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $setting = (new Setting('maxAttachmentsPerMessage'))->setValue(1);
        $em->persist($setting);
        $em->flush();

        $iris = [$this->uploadAttachment($user), $this->uploadAttachment($user)];

        $this->jsonClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/messages', [
            'json' => ['text' => 'Too many for setting', 'attachmentIris' => $iris],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testOwnerCanDeletePendingAttachment(): void
    {
        [$user] = $this->setupCommunityAndChannel();
        $file = $this->createUploadedFile();

        $response = $this->uploadClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $response->toArray()['@id'];
        $numericId = basename($id);

        $this->jsonClient($user)->request('DELETE', '/api/v1/attachments/'.$numericId);

        self::assertResponseStatusCodeSame(204);
    }

    public function testOtherUserCannotDeletePendingAttachment(): void
    {
        [$owner] = $this->setupCommunityAndChannel();
        $other = UserFactory::createOne();

        $file = $this->createUploadedFile();
        $response = $this->uploadClient($owner)->request('POST', '/api/v1/communities/att-c/channels/att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $numericId = basename($response->toArray()['@id']);

        $this->jsonClient($other)->request('DELETE', '/api/v1/attachments/'.$numericId);

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanDeleteLinkedAttachment(): void
    {
        [$user] = $this->setupCommunityAndChannel();
        $iri = $this->uploadAttachment($user);
        $numericId = basename($iri);

        $this->jsonClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/messages', [
            'json' => ['text' => 'With file', 'attachmentIris' => [$iri]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($user)->request('DELETE', '/api/v1/attachments/'.$numericId);

        self::assertResponseStatusCodeSame(204);
    }

    public function testModeratorCanDeleteLinkedAttachment(): void
    {
        $author = UserFactory::createOne();
        $moderator = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('mod-att-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        CommunityMemberFactory::createForUserAndCommunity($moderator, $community);

        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'mod-att-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($author, $channel);
        ChannelMemberFactory::createForUserAndChannel($moderator, $channel, ChannelRole::Moderator);

        $file = $this->createUploadedFile();
        $uploadResponse = $this->uploadClient($author)->request('POST', '/api/v1/communities/mod-att-c/channels/mod-att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $iri = $uploadResponse->toArray()['@id'];
        $numericId = basename($iri);

        $this->jsonClient($author)->request('POST', '/api/v1/communities/mod-att-c/channels/mod-att-ch/messages', [
            'json' => ['text' => 'With file', 'attachmentIris' => [$iri]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($moderator)->request('DELETE', '/api/v1/attachments/'.$numericId);

        self::assertResponseStatusCodeSame(204);
    }

    public function testRegularUserCannotDeleteAnotherUsersLinkedAttachment(): void
    {
        $author = UserFactory::createOne();
        $other = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('reg-att-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        CommunityMemberFactory::createForUserAndCommunity($other, $community);

        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'reg-att-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($author, $channel);
        ChannelMemberFactory::createForUserAndChannel($other, $channel);

        $file = $this->createUploadedFile();
        $uploadResponse = $this->uploadClient($author)->request('POST', '/api/v1/communities/reg-att-c/channels/reg-att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $iri = $uploadResponse->toArray()['@id'];
        $numericId = basename($iri);

        $this->jsonClient($author)->request('POST', '/api/v1/communities/reg-att-c/channels/reg-att-ch/messages', [
            'json' => ['text' => 'With file', 'attachmentIris' => [$iri]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($other)->request('DELETE', '/api/v1/attachments/'.$numericId);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletingMessageCascadesAttachmentDeletion(): void
    {
        [$user] = $this->setupCommunityAndChannel();
        $iri = $this->uploadAttachment($user);
        $numericAttachmentId = basename($iri);

        $msgResponse = $this->jsonClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/messages', [
            'json' => ['text' => 'With attachment', 'attachmentIris' => [$iri]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $messageId = basename($msgResponse->toArray()['@id']);

        $this->jsonClient($user)->request('DELETE', '/api/v1/messages/'.$messageId);
        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $remaining = $em->getRepository(MediaObject::class)->find($numericAttachmentId);
        self::assertNull($remaining);
    }

    public function testAdminCanDisableAttachmentsForChannel(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('toggle-c')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'toggle-ch'])->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/toggle-c/channels/toggle-ch', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['allowAttachments' => false],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['allowAttachments' => false]);
    }

    public function testChannelAllowAttachmentsIsTrueByDefault(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('default-att-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'default-att-ch'])->create();

        $this->jsonClient($user)->request('GET', '/api/v1/communities/default-att-c/channels/default-att-ch');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['allowAttachments' => true]);
    }

    public function testAnonymousCanViewAttachmentInPublicChannel(): void
    {
        [$user] = $this->setupCommunityAndChannel();
        $iri = $this->uploadAttachment($user);

        $msgResponse = $this->jsonClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/messages', [
            'json' => ['text' => 'public msg', 'attachmentIris' => [$iri]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $contentUrl = $msgResponse->toArray()['attachments'][0]['contentUrl'];
        self::assertIsString($contentUrl);

        // Strip the host — token is now part of the path, no query string needed
        $relativeUrl = (string) parse_url($contentUrl, PHP_URL_PATH);

        // Anonymous request — no Authorization header; token is embedded in the path
        static::createClient()->request('GET', $relativeUrl);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * An unauthenticated visitor cannot fetch a pending attachment (not yet linked
     * to any message).
     */
    public function testAnonymousCannotViewPendingAttachment(): void
    {
        [$user] = $this->setupCommunityAndChannel();

        $file = $this->createUploadedFile();
        $response = $this->uploadClient($user)->request('POST', '/api/v1/communities/att-c/channels/att-ch/attachments', [
            'extra' => ['files' => ['file' => $file]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $contentUrl = $response->toArray()['contentUrl'];
        self::assertIsString($contentUrl);
        $relativeUrl = (string) parse_url($contentUrl, PHP_URL_PATH);

        static::createClient()->request('GET', $relativeUrl);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * An unauthenticated visitor cannot fetch an attachment from a private channel,
     * even if the community is public.
     */
    public function testAnonymousCannotViewAttachmentInPrivateChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('priv-ch-att-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()
            ->inCommunity($community)
            ->private()
            ->with(['identifier' => 'priv-ch-att-ch', 'allowAttachments' => true])
            ->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $iri = $this->uploadAttachment($user, 'priv-ch-att-c', 'priv-ch-att-ch');

        $msgResponse = $this->jsonClient($user)->request('POST', '/api/v1/communities/priv-ch-att-c/channels/priv-ch-att-ch/messages', [
            'json' => ['text' => 'private msg', 'attachmentIris' => [$iri]],
        ]);
        self::assertResponseStatusCodeSame(201);

        $contentUrl = $msgResponse->toArray()['attachments'][0]['contentUrl'];
        $relativeUrl = (string) parse_url($contentUrl, PHP_URL_PATH);

        static::createClient()->request('GET', $relativeUrl);

        self::assertResponseStatusCodeSame(401);
    }
}
