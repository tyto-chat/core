<?php

declare(strict_types=1);

namespace App\Tests\Functional\Message;

use App\Entity\Conversation;
use App\Entity\User;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Functional\Conversation\ConversationPolicyFixturesTrait;
use App\Tests\Functional\E2ee\EnvelopeFixturesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class MessageTextLimitTest extends ApiTestCase
{
    use ConversationPolicyFixturesTrait;
    use EnvelopeFixturesTrait;
    use Factories;
    use ResetDatabase;

    /** @return iterable<string, array{string}> */
    public static function writes(): iterable
    {
        yield 'send' => ['send'];
        yield 'edit' => ['edit'];
        yield 'reply' => ['reply'];
    }

    #[DataProvider('writes')]
    public function testPlaintextOf10000MultibyteCharsAcceptedInPlainDm(string $write): void
    {
        $this->write($write, false, str_repeat('ż', 10000));

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('writes')]
    public function testPlaintextOf10001CharsRejected(string $write): void
    {
        $this->write($write, false, str_repeat('a', 10001));

        self::assertResponseStatusCodeSame(422);
    }

    #[DataProvider('writes')]
    public function testLargestEnvelopeUnderCapAcceptedInEncryptedDm(string $write): void
    {
        $this->write($write, true, self::envelopeOfBytes(65535));

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('writes')]
    public function testEnvelopeOf65537BytesRejected(string $write): void
    {
        $response = $this->write($write, true, 'e2ee:1:'.str_repeat('A', 65530));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('65536', $response->getContent(false));
    }

    #[DataProvider('writes')]
    public function testEnvelopeLongerThan10000CharsButUnderCapAccepted(string $write): void
    {
        $this->write($write, true, self::envelopeOfBytes(20003));

        self::assertResponseIsSuccessful();
    }

    private function write(string $write, bool $encrypted, string $text): ResponseInterface
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $conversation = $this->conversationBetween([$a, $b], $encrypted);
        if ('send' === $write) {
            return $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
                'json' => ['text' => $text],
            ]);
        }

        $seedIri = $this->send($a, $conversation, $encrypted ? self::envelope() : 'seed');
        if ('edit' === $write) {
            return $this->jsonClient($a)->request('PATCH', $seedIri, [
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['text' => $text],
            ]);
        }

        return $this->jsonClient($b)->request('POST', $seedIri.'/replies', ['json' => ['text' => $text]]);
    }

    private function send(User $as, Conversation $conversation, string $text): string
    {
        $body = $this->jsonClient($as)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => $text],
        ])->toArray(false);

        return (string) ($body['@id'] ?? '');
    }
}
