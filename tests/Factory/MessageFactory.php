<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\MessageRevision;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Message>
 */
final class MessageFactory extends PersistentObjectFactory
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    #[\Override]
    public static function class(): string
    {
        return Message::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'page' => MessagePageFactory::new(),
            'createdBy' => UserFactory::new(),
            'deleted' => false,
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this->afterPersist(function (Message $message): void {
            if ($message->getRevisions()->isEmpty()) {
                $revision = new MessageRevision();
                $revision->setText(self::faker()->sentence());
                $message->addRevision($revision);
                $this->em->persist($revision);
                $message->setText($revision->getText());
                $this->em->flush();
            }
        });
    }

    public function deleted(): static
    {
        return $this->with(['deleted' => true]);
    }

    /**
     * Persist with an explicit revision (and matching text). The default
     * initialize() hook auto-creates a faker-generated revision when none
     * exists, so to force specific content, install the revision up front.
     */
    public function withText(string $text): static
    {
        $revision = new MessageRevision();
        $revision->setText($text);
        $this->em->persist($revision);

        return $this->afterInstantiate(function (Message $message) use ($revision, $text): void {
            $message->addRevision($revision);
            $message->setText($text);
        });
    }

    public function inPage(MessagePage $page): static
    {
        return $this->with(['page' => $page]);
    }

    public function byUser(User $user): static
    {
        return $this->with(['createdBy' => $user]);
    }
}
