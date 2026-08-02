<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Verifies that API error messages are returned in the language negotiated
 * via the Accept-Language header (currently: en / pl).
 */
class LocaleTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAlreadyMemberExceptionInPolish(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('join-pl')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        // Joining twice triggers AlreadyMemberException → 409
        $this->jsonClient($user, 'pl')->request('POST', '/api/v1/communities/join-pl/members');

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['error' => 'Jesteś już członkiem tej społeczności.']);
    }

    public function testAlreadyMemberExceptionInEnglish(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('join-en')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user)->request('POST', '/api/v1/communities/join-en/members');

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['error' => 'You are already a member of this community.']);
    }

    public function testEnglishFallbackForUnsupportedLocale(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('join-ja')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        // ja is not a supported locale → falls back to en
        $this->jsonClient($user, 'ja')->request('POST', '/api/v1/communities/join-ja/members');

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['error' => 'You are already a member of this community.']);
    }

    public function testEmailAvailableViolationInPolish(): void
    {
        UserFactory::createOne(['email' => 'existing@example.com']);

        $response = $this->jsonClient(locale: 'pl')->request('POST', '/api/v1/users', ['json' => [
            'email' => 'existing@example.com',
            'password' => 'securepass123',
        ]]);

        self::assertResponseStatusCodeSame(422);
        $violations = $response->toArray(throw: false)['violations'] ?? [];
        $messages = array_column($violations, 'message');
        self::assertContains('Ten adres e-mail jest już zarejestrowany.', $messages);
    }

    public function testEmailAvailableViolationInEnglish(): void
    {
        UserFactory::createOne(['email' => 'existing@example.com']);

        $response = $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'existing@example.com',
            'password' => 'securepass123',
        ]]);

        self::assertResponseStatusCodeSame(422);
        $violations = $response->toArray(throw: false)['violations'] ?? [];
        $messages = array_column($violations, 'message');
        self::assertContains('This email address is already registered.', $messages);
    }

    public function testQualityWeightedLocaleNegotiation(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('join-q')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        // ja not supported → pl (q=0.8) is the next best supported locale
        $this->jsonClient($user, 'ja;q=1.0, pl;q=0.8, en;q=0.5')
            ->request('POST', '/api/v1/communities/join-q/members');

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['error' => 'Jesteś już członkiem tej społeczności.']);
    }

    public function testAlreadyMemberExceptionInFrench(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('join-fr')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user, 'fr')->request('POST', '/api/v1/communities/join-fr/members');

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['error' => 'Tu es déjà membre de cette communauté.']);
    }

    public function testAlreadyMemberExceptionInGerman(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('join-ger')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user, 'de')->request('POST', '/api/v1/communities/join-ger/members');

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['error' => 'Du bist bereits Mitglied dieser Community.']);
    }

    public function testAlreadyMemberExceptionInSpanish(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('join-es')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user, 'es')->request('POST', '/api/v1/communities/join-es/members');

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['error' => 'Ya eres miembro de esta comunidad.']);
    }

    public function testAlreadyMemberExceptionInItalian(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('join-ita')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user, 'it')->request('POST', '/api/v1/communities/join-ita/members');

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['error' => 'Sei già membro di questa community.']);
    }
}
