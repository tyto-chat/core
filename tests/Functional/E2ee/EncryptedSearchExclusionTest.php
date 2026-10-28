<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Search\SearchReindexServiceInterface;
use App\Service\Search\SearchServiceInterface;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Functional\Conversation\ConversationPolicyFixturesTrait;
use App\Tests\Stub\InMemorySearchService;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class EncryptedSearchExclusionTest extends ApiTestCase
{
    use ConversationPolicyFixturesTrait;
    use EnvelopeFixturesTrait;
    use Factories;
    use ResetDatabase;

    public function testEncryptedRootNotIndexedIncrementally(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $root = $this->message($conversation, $a);

        $this->search()->injectDocument(['id' => $root->getId(), 'text' => 'stale', 'conversationId' => $conversation->getId()]);
        $this->search()->indexMessage($root);

        self::assertFalse($this->search()->hasDocument($root->getId()));
    }

    public function testEncryptedReplyNotIndexedIncrementally(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $root = $this->message($conversation, $a);
        $reply = $this->message($conversation, $b, $root);

        $this->search()->indexMessage($reply);

        self::assertFalse($this->search()->hasDocument($reply->getId()));
    }

    public function testPlainDmStillIndexed(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $plain = $this->conversationBetween([$a, $b]);
        $message = $this->message($plain, $a, text: 'findable');

        $this->search()->indexMessage($message);

        self::assertTrue($this->search()->hasDocument($message->getId()));
    }

    public function testReindexCountExcludesEncryptedRows(): void
    {
        [$a, $b, $encrypted] = $this->encryptedPair();
        $plain = $this->conversationBetween([$a, $b]);
        $this->message($encrypted, $a);
        $this->message($encrypted, $b);
        $this->message($plain, $a, text: 'plain');

        self::assertSame(1, $this->reindexer()->countIndexable());
    }

    public function testReindexBatchesSkipEncryptedRows(): void
    {
        [$a, $b, $encrypted] = $this->encryptedPair();
        $plain = $this->conversationBetween([$a, $b]);
        $secret = $this->message($encrypted, $a);
        $visible = $this->message($plain, $a, text: 'plain');

        $indexed = $this->reindexer()->reindex();

        self::assertSame(1, $indexed);
        self::assertTrue($this->search()->hasDocument($visible->getId()));
        self::assertFalse($this->search()->hasDocument($secret->getId()));
    }

    public function testReindexRemovesPreviouslyIndexedEncryptedDocument(): void
    {
        [$a, , $encrypted] = $this->encryptedPair();
        $secret = $this->message($encrypted, $a);
        $this->search()->injectDocument(['id' => $secret->getId(), 'text' => 'leaked', 'conversationId' => $encrypted->getId(), 'channelId' => null]);
        $this->search()->injectDocument(['id' => 'orphan-doc', 'text' => 'leaked', 'conversationId' => $encrypted->getId(), 'channelId' => null]);

        $this->reindexer()->reindex();

        self::assertFalse($this->search()->hasDocument($secret->getId()));
        self::assertFalse($this->search()->hasDocument('orphan-doc'));
    }

    public function testConversationSearchReturnsNothingForEncrypted(): void
    {
        [$a, , $encrypted] = $this->encryptedPair();
        $secret = $this->message($encrypted, $a);
        $this->search()->injectDocument(['id' => $secret->getId(), 'text' => 'needle', 'conversationId' => $encrypted->getId(), 'channelId' => null, 'createdAt' => time()]);

        $body = $this->jsonClient($a)->request('GET', '/api/v1/conversations/'.$encrypted->getIdentifier().'/search?q=needle')->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['hits']);
    }

    private function search(): InMemorySearchService
    {
        $service = static::getContainer()->get(SearchServiceInterface::class);
        \assert($service instanceof InMemorySearchService);

        return $service;
    }

    private function reindexer(): SearchReindexServiceInterface
    {
        $service = static::getContainer()->get(SearchReindexServiceInterface::class);
        \assert($service instanceof SearchReindexServiceInterface);

        return $service;
    }

    private function message(Conversation $conversation, User $author, ?Message $parent = null, ?string $text = null): Message
    {
        $page = $parent?->getPage() ?? MessagePageFactory::new()->forConversation($conversation)->create();
        $factory = MessageFactory::new()->inPage($page)->byUser($author)->withText($text ?? self::envelope());
        if (null !== $parent) {
            $factory = $factory->with(['parent' => $parent]);
        }

        return $factory->create();
    }
}
