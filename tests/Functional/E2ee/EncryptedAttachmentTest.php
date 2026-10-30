<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use App\Entity\Conversation;
use App\Entity\MediaObject;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\Gdpr\DataExportCollector;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Functional\Conversation\ConversationPolicyFixturesTrait;
use App\Tests\Stub\RecordingMercureHub;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class EncryptedAttachmentTest extends ApiTestCase
{
    use ConversationPolicyFixturesTrait;
    use EnvelopeFixturesTrait;
    use Factories;
    use ResetDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testEncryptedUploadAcceptsOctetStream(): void
    {
        [$a, , $conversation] = $this->encryptedPair();

        $body = $this->uploadEncrypted($a, $conversation, random_bytes(512));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('encrypted_attachment', $body['type']);
        self::assertSame(512, $body['size']);
        self::assertIsString($body['contentUrl']);
    }

    public function testEncryptedUploadStoresNoNameMimeOrDimensions(): void
    {
        [$a, , $conversation] = $this->encryptedPair();

        $body = $this->uploadEncrypted($a, $conversation, (string) base64_decode(self::PNG, true), 'holiday-photo.png', 'image/png');

        self::assertResponseStatusCodeSame(201);
        $media = $this->media($body);
        self::assertNull($media->originalName);
        self::assertSame('application/octet-stream', $media->mimeType);
        self::assertNull($media->width);
        self::assertNull($media->height);
        self::assertNull($media->animated);
        self::assertStringNotContainsString('holiday', (string) $media->filePath);
        foreach (['originalName', 'mimeType', 'width', 'height', 'animated', 'thumbnailUrl'] as $field) {
            self::assertArrayNotHasKey($field, $body);
        }
    }

    public function testEncryptedUploadWritesNoThumbnailVariants(): void
    {
        [$a, , $conversation] = $this->encryptedPair();

        $body = $this->uploadEncrypted($a, $conversation, (string) base64_decode(self::PNG, true), 'x.png', 'image/png');

        self::assertResponseStatusCodeSame(201);
        $media = $this->media($body);
        $mediaDir = self::getContainer()->getParameter('kernel.project_dir').'/var/media';
        self::assertFileExists($mediaDir.'/'.$media->filePath);
        self::assertFileDoesNotExist($mediaDir.'/attachment_thumb/'.$media->filePath);
    }

    public function testEncryptedUploadOverCiphertextCapRejected(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $this->setting('maxAttachmentSizeMb', 1);

        $this->uploadEncrypted($a, $conversation, random_bytes(1024 * 1024 + 1025));
        self::assertResponseStatusCodeSame(422);

        $this->uploadEncrypted($a, $conversation, random_bytes(1024 * 1024 + 1024));
        self::assertResponseStatusCodeSame(201);
    }

    public function testEncryptedUploadOnPlainConversationRejected(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $conversation = $this->conversationBetween([$a, $b]);

        $this->uploadEncrypted($a, $conversation, random_bytes(64));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->mediaCount());
    }

    public function testPlainUploadOnEncryptedConversationRejected(): void
    {
        [$a, , $conversation] = $this->encryptedPair();

        $this->uploadPlain($a, $conversation);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->mediaCount());
    }

    public function testEncryptedAttachmentLinksIntoEncryptedMessageAndReply(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $first = $this->uploadEncrypted($a, $conversation, random_bytes(64))['@id'];
        $second = $this->uploadEncrypted($a, $conversation, random_bytes(64))['@id'];

        $root = $this->send($a, $conversation, ['text' => self::envelope(), 'attachmentIris' => [$first]]);
        self::assertResponseStatusCodeSame(201);
        $reply = $this->reply($a, $root, ['text' => self::envelope(), 'attachmentIris' => [$second]]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame([$first], array_column($root['attachments'], '@id'));
        self::assertSame([$second], array_column($reply['attachments'], '@id'));
    }

    public function testPlainAttachmentCannotLinkIntoEncryptedMessage(): void
    {
        [$a, $b, $encrypted] = $this->encryptedPair();
        $plain = $this->conversationBetween([$a, $b]);
        $iri = $this->uploadPlain($a, $plain)['@id'];

        $this->send($a, $encrypted, ['text' => self::envelope(), 'attachmentIris' => [$iri]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->messageCount($encrypted));
        self::assertNull($this->media(['@id' => $iri])->message);
    }

    public function testEncryptedAttachmentCannotLinkIntoPlainMessage(): void
    {
        [$a, $b, $encrypted] = $this->encryptedPair();
        $plain = $this->conversationBetween([$a, $b]);
        $iri = $this->uploadEncrypted($a, $encrypted, random_bytes(64))['@id'];

        $this->send($a, $plain, ['text' => 'hello', 'attachmentIris' => [$iri]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->messageCount($plain));
        self::assertNull($this->media(['@id' => $iri])->message);
    }

    public function testEncryptedAttachmentCannotLinkIntoChannelMessage(): void
    {
        [$a, , $encrypted] = $this->encryptedPair();
        $community = CommunityFactory::new()->withIdentifier('enc-att-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'enc-att-ch', 'allowAttachments' => true])->create();
        $iri = $this->uploadEncrypted($a, $encrypted, random_bytes(64))['@id'];

        $this->jsonClient($a)->request('POST', '/api/v1/communities/enc-att-c/channels/enc-att-ch/messages', [
            'json' => ['text' => 'hello', 'attachmentIris' => [$iri]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->media(['@id' => $iri])->message);
        self::assertSame(0, (int) $this->em()->createQuery('SELECT COUNT(m.id) FROM App\Entity\Message m')->getSingleScalarResult());
    }

    public function testMercureAndExportPayloadsCarryNoOriginalName(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $iri = $this->uploadEncrypted($a, $conversation, random_bytes(64), 'secret-plans.pdf', 'application/pdf')['@id'];
        RecordingMercureHub::reset();

        $sent = $this->send($a, $conversation, ['text' => self::envelope(), 'attachmentIris' => [$iri]]);
        self::assertResponseStatusCodeSame(201);

        $published = [];
        foreach (RecordingMercureHub::updates() as $update) {
            $data = json_decode($update->getData(), true);
            if (is_array($data) && isset($data['attachments'])) {
                $published = array_merge($published, $data['attachments']);
            }
        }
        self::assertNotSame([], $published);
        self::assertSame([], array_diff(array_keys($sent['attachments'][0]), ['@id', '@type', 'type', 'size', 'contentUrl']));
        foreach ($published as $attachment) {
            self::assertSame([], array_diff(array_keys($attachment), ['@id', 'type', 'size', 'contentUrl']));
        }

        $fetched = $this->jsonClient($b)->request('GET', $sent['@id'])->toArray();
        self::assertSame([], array_diff(array_keys($fetched['attachments'][0]), ['@id', '@type', 'type', 'size', 'contentUrl']));

        $collector = self::getContainer()->get(DataExportCollector::class);
        $user = $this->em()->find(User::class, $a->getId());
        $rows = array_column($collector->messages($user, 60), null, 'conversationIdentifier');
        $exported = $rows[$conversation->getIdentifier()]['attachments'][0];
        self::assertSame([], array_diff(array_keys($exported), ['id', 'type', 'size', 'signedUrl']));
        $uploads = $collector->attachments($user, 60);
        self::assertCount(1, $uploads);
        self::assertSame([], array_diff(array_keys($uploads[0]), ['id', 'type', 'size', 'createdAt', 'signedUrl']));
        self::assertStringNotContainsString('secret-plans', (string) json_encode([$sent, $fetched, $published, $rows, $uploads]));
    }

    public function testNonParticipantCannotViewEncryptedAttachment(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $outsider = UserFactory::createOne();
        $upload = $this->uploadEncrypted($a, $conversation, random_bytes(64));
        $this->send($a, $conversation, ['text' => self::envelope(), 'attachmentIris' => [$upload['@id']]]);
        $path = (string) parse_url($upload['contentUrl'], PHP_URL_PATH);

        $this->jsonClient($b)->request('GET', $path);
        self::assertResponseStatusCodeSame(200);

        $this->jsonClient($outsider)->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEncryptedAttachmentServedAsOpaqueDownload(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $upload = $this->uploadEncrypted($a, $conversation, '<html><body><script>alert(1)</script></body></html>');
        self::assertResponseStatusCodeSame(201);

        $response = $this->jsonClient($a)->request('GET', (string) parse_url($upload['contentUrl'], PHP_URL_PATH));

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        self::assertStringStartsWith('attachment', $response->getHeaders(false)['content-disposition'][0] ?? '');
    }

    /** @return array<string, mixed> */
    private function uploadEncrypted(User $as, Conversation $conversation, string $bytes, ?string $name = null, string $mime = 'application/octet-stream'): array
    {
        return $this->upload($as, '/api/v1/conversations/'.$conversation->getIdentifier().'/encrypted-attachments', $bytes, $name ?? Uuid::v4()->toRfc4122().'.bin', $mime);
    }

    /** @return array<string, mixed> */
    private function uploadPlain(User $as, Conversation $conversation): array
    {
        return $this->upload($as, '/api/v1/conversations/'.$conversation->getIdentifier().'/attachments', (string) base64_decode(self::PNG, true), 'photo.png', 'image/png');
    }

    /** @return array<string, mixed> */
    private function upload(User $as, string $url, string $bytes, string $name, string $mime): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'enc_att_');
        file_put_contents($tmp, $bytes);

        return $this->uploadClient($as)->request('POST', $url, [
            'extra' => ['files' => ['file' => new UploadedFile($tmp, $name, $mime, null, true)]],
        ])->toArray(false);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function send(User $as, Conversation $conversation, array $json): array
    {
        return $this->jsonClient($as)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => $conversation->isEncrypted() ? $json + ['id' => Uuid::v4()->toRfc4122()] : $json,
        ])->toArray(false);
    }

    /**
     * @param array<string, mixed> $root
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function reply(User $as, array $root, array $json): array
    {
        return $this->jsonClient($as)->request('POST', $root['@id'].'/replies', [
            'json' => $json + ['id' => Uuid::v4()->toRfc4122()],
        ])->toArray(false);
    }

    /** @param array<string, mixed> $body */
    private function media(array $body): MediaObject
    {
        $this->em()->clear();
        $id = (int) substr((string) $body['@id'], (int) strrpos((string) $body['@id'], '/') + 1);
        $media = $this->em()->find(MediaObject::class, $id);
        self::assertInstanceOf(MediaObject::class, $media);

        return $media;
    }

    private function mediaCount(): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(mo.id) FROM App\Entity\MediaObject mo')->getSingleScalarResult();
    }

    private function messageCount(Conversation $conversation): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(m.id) FROM App\Entity\Message m JOIN m.page p WHERE p.conversation = :c')
            ->setParameter('c', $conversation->getId())
            ->getSingleScalarResult();
    }

    private function setting(string $name, int $value): void
    {
        $this->em()->persist((new Setting($name))->setValue($value));
        $this->em()->flush();
    }
}
