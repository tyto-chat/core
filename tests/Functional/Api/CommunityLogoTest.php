<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommunityLogoTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function minimalPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    private function uploadLogo(User $actor, string $identifier): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'logo_test_');
        file_put_contents((string) $tmp, $this->minimalPng());
        $file = new UploadedFile((string) $tmp, 'logo.png', 'image/png', null, true);
        $this->uploadClient($actor)->request(
            'POST',
            '/api/v1/communities/'.$identifier.'/logo',
            ['extra' => ['files' => ['file' => $file]]],
        );
        self::assertResponseStatusCodeSame(201);
    }

    public function testGlobalAdminCanRemoveLogo(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('logo-rm')->create();
        $this->uploadLogo($admin, 'logo-rm');

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/logo-rm/logo');

        self::assertResponseStatusCodeSame(204);
        $data = $this->jsonClient($admin)->request('GET', '/api/v1/communities/logo-rm')->toArray();
        self::assertNull($data['logo']);
    }

    public function testReuploadReplacesExistingLogo(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('logo-reupload')->create();

        // First upload, then a second that must replace it — deleting the old
        // MediaObject only after the community FK is repointed (else a RESTRICT
        // FK violation 500s the replace).
        $this->uploadLogo($admin, 'logo-reupload');
        $this->uploadLogo($admin, 'logo-reupload');

        $data = $this->jsonClient($admin)->request('GET', '/api/v1/communities/logo-reupload')->toArray();
        self::assertNotNull($data['logo']);
    }

    public function testMemberCannotRemoveLogo(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('logo-rm-deny')->create();
        CommunityMemberFactory::createOne([
            'user' => $member,
            'community' => $community,
            'role' => CommunityRole::Member,
        ]);
        $this->uploadLogo($admin, 'logo-rm-deny');

        $this->jsonClient($member)->request('DELETE', '/api/v1/communities/logo-rm-deny/logo');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRemoveWithoutLogoReturns404(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('logo-rm-none')->create();

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/logo-rm-none/logo');

        self::assertResponseStatusCodeSame(404);
    }
}
