<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Dto\Admin\ServerConfigPatchDto;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Enum\Message\MessageKind;
use App\Service\Settings\SettingsServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityEmojiFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class MessageLinkTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private const string UNKNOWN_UUID = '00000000-0000-7000-8000-000000000000';

    public function testPublicMessageRendersCardAndAppLinks(): void
    {
        $message = $this->publicMessage('Hello <b>embed</b><script>alert(1)</script>');
        $uuid = $message->getId();

        $response = static::createClient()->request('GET', '/m/'.$uuid);

        self::assertResponseIsSuccessful();
        $html = $response->getContent();
        self::assertStringContainsString('Hello embed', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('href="tyto://open?url=http%3A%2F%2Flocalhost%2Fm%2F'.$uuid.'"', $html);
        self::assertStringContainsString('Open in Tyto desktop', $html);
        self::assertStringNotContainsString('Open in web app', $html);
        self::assertStringContainsString('#open · ', $html);
        self::assertStringContainsString('property="og:title"', $html);
        self::assertSame('DENY', $response->getHeaders(false)['x-frame-options'][0] ?? null);
    }

    public function testLandingOffersWebAppWhenClientBaseUrlIsConfigured(): void
    {
        $message = $this->publicMessage('web link');
        $client = static::createClient();
        $patch = new ServerConfigPatchDto();
        $patch->clientBaseUrl = 'https://app.example.test';
        static::getContainer()->get(SettingsServiceInterface::class)->applyPatch($patch);

        $html = $client->request('GET', '/m/'.$message->getId())->getContent();

        self::assertStringContainsString('href="https://app.example.test/m/'.$message->getId().'"', $html);
        self::assertStringContainsString('Open in web app', $html);
    }

    public function testPrivateChannelMessageLooksLikeAMissingOne(): void
    {
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('link-priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'vault'])->create();
        ChannelMemberFactory::createForUserAndChannel($author, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->withText('top secret')->create();

        $client = static::createClient();
        $private = $client->request('GET', '/m/'.$message->getId());
        self::assertResponseIsSuccessful();
        $privateHtml = $private->getContent();
        $missingHtml = $client->request('GET', '/m/'.self::UNKNOWN_UUID)->getContent();
        self::assertResponseIsSuccessful();

        self::assertStringNotContainsString('top secret', $privateHtml);
        self::assertStringContainsString('Message unavailable', $privateHtml);
        self::assertStringContainsString('Open in Tyto desktop', $privateHtml);
        self::assertSame(
            str_replace((string) $message->getId(), self::UNKNOWN_UUID, $privateHtml),
            $missingHtml,
        );
    }

    public function testDeletedAndSystemMessagesAreUnavailable(): void
    {
        $community = CommunityFactory::new()->withIdentifier('link-gone')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'open'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $deleted = MessageFactory::new()->inPage($page)->withText('gone text')->deleted()->create();
        $system = MessageFactory::new()->inPage($page)->withText('system text')->with(['kind' => MessageKind::System])->create();

        $client = static::createClient();
        $deletedHtml = $client->request('GET', '/embed/m/'.$deleted->getId())->getContent();
        $systemHtml = $client->request('GET', '/embed/m/'.$system->getId())->getContent();

        self::assertStringNotContainsString('gone text', $deletedHtml);
        self::assertStringNotContainsString('system text', $systemHtml);
        self::assertStringContainsString('Message unavailable', $deletedHtml);
        self::assertStringContainsString('Message unavailable', $systemHtml);
    }

    public function testEmbedIsFrameableAndReportsHeight(): void
    {
        $message = $this->publicMessage('framed text');

        $response = static::createClient()->request('GET', '/embed/m/'.$message->getId());

        self::assertResponseIsSuccessful();
        $html = $response->getContent();
        self::assertStringContainsString('framed text', $html);
        self::assertStringContainsString('tyto-embed-height', $html);
        self::assertArrayNotHasKey('x-frame-options', $response->getHeaders(false));
        self::assertStringContainsString('max-age=60', $response->getHeaders(false)['cache-control'][0] ?? '');
    }

    public function testCustomEmojisRenderAsImagesOutsideCode(): void
    {
        $community = CommunityFactory::new()->withIdentifier('link-emoji')->create();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $image = new MediaObject();
        $image->filePath = 'party.png';
        $image->type = 'community_emoji';
        $em->persist($image);
        $em->flush();
        CommunityEmojiFactory::new()->with(['community' => $community, 'shortcode' => ':party:', 'image' => $image])->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'open'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->withText('<p>yay :party: <code>:party:</code></p>')->create();

        $html = static::createClient()->request('GET', '/embed/m/'.$message->getId())->getContent();

        self::assertMatchesRegularExpression('~yay <img[^>]*alt=":party:"[^>]*class="embed-emoji"~', $html);
        self::assertStringContainsString('<code>:party:</code>', $html);
        self::assertStringContainsString('party.png', $html);
    }

    public function testMarkdownRendersLikeTheClient(): void
    {
        $community = CommunityFactory::new()->withIdentifier('link-md')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'open'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)
            ->withText("**bold** hello [@Ann](user:1) in [#open](channel:2)\n\nsee [docs](https://example.com) ~~old~~")
            ->create();

        $html = static::createClient()->request('GET', '/m/'.$message->getId())->getContent();

        self::assertStringContainsString('<strong>bold</strong> hello <span class="mention-user" data-user-id="1">&#64;Ann</span>', $html);
        self::assertStringContainsString('<span class="mention-channel" data-channel-id="2">#open</span>', $html);
        self::assertStringContainsString('see docs old', $html);
        self::assertStringNotContainsString('**bold**', $html);
        self::assertStringNotContainsString('(user:1)', $html);
        self::assertStringNotContainsString('href="https://example.com"', $html);
        self::assertMatchesRegularExpression('~og:description" content="bold hello @Ann in #open see docs old"~', $html);
    }

    public function testCardShowsMediaFilesReactionsAndReplies(): void
    {
        $message = $this->publicMessage('with extras');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([['a1.png', 'image/png'], ['a2.jpg', 'image/jpeg'], ['clip.mp4', 'video/mp4'], ['doc.pdf', 'application/pdf']] as [$name, $mime]) {
            $media = new MediaObject();
            $media->filePath = $name;
            $media->type = 'attachment';
            $media->mimeType = $mime;
            $media->message = $message;
            $em->persist($media);
        }
        $message->setReactions(['👍' => [['id' => 1, 'userId' => 1], ['id' => 2, 'userId' => 2]], '🎉' => [['id' => 3, 'userId' => 3]]]);
        $message->incrementReplyCount();
        $message->incrementReplyCount();
        $em->flush();

        $em->clear();

        $html = static::createClient()->request('GET', '/embed/m/'.$message->getId())->getContent();

        self::assertSame(2, substr_count($html, 'class="embed-attachment-image-wrap"'));
        self::assertStringContainsString('a1.png', $html);
        self::assertStringContainsString('a2.jpg', $html);
        self::assertStringNotContainsString('clip.mp4', $html);
        self::assertStringContainsString('<span class="embed-attachment-more">+1</span>', $html);
        self::assertMatchesRegularExpression('~embed-attachment-badge">.*?</svg> 1</div>~s', $html);
        self::assertStringContainsString('👍', $html);
        self::assertStringContainsString('<span class="embed-reaction-count">2</span>', $html);
        self::assertStringContainsString('2 replies', $html);
    }

    public function testUppercaseUuidResolvesTheSameMessage(): void
    {
        $message = $this->publicMessage('case insensitive');

        $html = static::createClient()->request('GET', '/m/'.strtoupper($message->getId()))->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('case insensitive', $html);
        self::assertStringContainsString('/m/'.$message->getId().'"', $html);
    }

    private function publicMessage(string $text): Message
    {
        $community = CommunityFactory::new()->withIdentifier('link-pub-'.bin2hex(random_bytes(3)))->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'open'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();

        return MessageFactory::new()->inPage($page)->withText($text)->create();
    }
}
