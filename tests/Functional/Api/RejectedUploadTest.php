<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\MediaObject;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class RejectedUploadTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function png(): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'rejected_upload_');
        file_put_contents($tmp, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));

        return new UploadedFile($tmp, 'x.png', 'image/png', null, true);
    }

    private function mediaCount(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em->getRepository(MediaObject::class)->count([]);
    }

    public function testMemberLogoUploadIsRejectedWithoutStoringTheFile(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rej-logo')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->uploadClient($member)->request('POST', '/api/v1/communities/rej-logo/logo', [
            'extra' => ['files' => ['file' => $this->png()]],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->mediaCount());
    }

    public function testMemberCustomEmojiUploadIsRejectedWithoutStoringTheFile(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rej-emoji')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->uploadClient($member)->request('POST', '/api/v1/communities/rej-emoji/emojis/custom', [
            'extra' => ['files' => ['file' => $this->png()], 'parameters' => ['shortcode' => ':nope:']],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->mediaCount());
    }
}
