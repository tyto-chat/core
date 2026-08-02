<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class UserPreferenceTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private const array MERGE_PATCH = ['headers' => ['Content-Type' => 'application/merge-patch+json']];

    public function testGetReturnsDefaultsForNewUser(): void
    {
        $user = UserFactory::createOne();
        $response = $this->jsonClient($user)->request('GET', '/api/v1/me/preferences');
        self::assertResponseIsSuccessful();
        $data = $response->toArray();

        foreach (['theme', 'submitKey', 'locale', 'timezone', 'sendTypingIndicator', 'desktopNotifications', 'convertEmoticons', 'resumeLastLocation'] as $key) {
            self::assertArrayHasKey($key, $data);
            self::assertNull($data[$key], "Expected null default for $key");
        }
        self::assertArrayHasKey('updatedAt', $data);
    }

    public function testAnonymousIsRejected(): void
    {
        $this->jsonClient()->request('GET', '/api/v1/me/preferences');
        self::assertResponseStatusCodeSame(401);
    }

    public function testPatchPersistsAllowedFields(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);

        $client->request('PATCH', '/api/v1/me/preferences', [
            ...self::MERGE_PATCH,
            'json' => [
                'theme' => 'dark',
                'submitKey' => 'ctrl+enter',
                'locale' => 'pl',
                'timezone' => 'Europe/Warsaw',
                'sendTypingIndicator' => false,
                'desktopNotifications' => true,
                'convertEmoticons' => false,
                'resumeLastLocation' => false,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $get = $client->request('GET', '/api/v1/me/preferences')->toArray();
        self::assertSame('dark', $get['theme']);
        self::assertSame('ctrl+enter', $get['submitKey']);
        self::assertSame('pl', $get['locale']);
        self::assertSame('Europe/Warsaw', $get['timezone']);
        self::assertFalse($get['sendTypingIndicator']);
        self::assertTrue($get['desktopNotifications']);
        self::assertFalse($get['convertEmoticons']);
        self::assertFalse($get['resumeLastLocation']);
    }

    public function testPatchSparseLeavesOmittedFieldsAlone(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);

        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['theme' => 'dark', 'submitKey' => 'ctrl+enter']]);
        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['theme' => 'light']]);

        $get = $client->request('GET', '/api/v1/me/preferences')->toArray();
        self::assertSame('light', $get['theme']);
        self::assertSame('ctrl+enter', $get['submitKey'], 'submitKey must survive a sparse patch that did not include it.');
    }

    public function testPatchNullClearsField(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);

        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['theme' => 'dark']]);
        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['theme' => null]]);

        $get = $client->request('GET', '/api/v1/me/preferences')->toArray();
        self::assertNull($get['theme']);
    }

    public function testPatchInvalidEnumIs422(): void
    {
        $user = UserFactory::createOne();
        $this->jsonClient($user)->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['theme' => 'nope']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPatchWrongTypeIs422(): void
    {
        $user = UserFactory::createOne();
        $this->jsonClient($user)->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['sendTypingIndicator' => 'yes']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSectionCollapseRoundTrips(): void
    {
        $caller = UserFactory::createOne();

        $client = $this->jsonClient($caller);
        $client->request('PATCH', '/api/v1/me/preferences', [
            ...self::MERGE_PATCH,
            'json' => ['sectionCollapse' => ['hidden:5' => true, '12' => false]],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/me/preferences');
        self::assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        self::assertSame(['hidden:5' => true, '12' => false], $body['sectionCollapse']);
    }
}
