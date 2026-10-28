<?php

declare(strict_types=1);

namespace App\Dto\UserPreference;

use App\Dto\EntityDtoInterface;
use App\Entity\UserPreference;
use App\Enum\Settings\SupportedLocale;
use App\Enum\User\RemoteContentLevel;
use App\Enum\User\UserSubmitKey;
use App\Enum\User\UserTheme;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** Properties stay uninitialized on purpose — applyTo() distinguishes omitted (uninitialized) from explicit null; do not add defaults. */
class UpdateUserPreferencesDto implements EntityDtoInterface
{
    #[Groups(['user_preference:write'])]
    public ?UserTheme $theme;

    #[Groups(['user_preference:write'])]
    public ?UserSubmitKey $submitKey;

    #[Groups(['user_preference:write'])]
    public ?SupportedLocale $locale;

    #[Assert\Length(max: 64)]
    #[Groups(['user_preference:write'])]
    public ?string $timezone;

    #[Groups(['user_preference:write'])]
    public ?bool $sendTypingIndicator;

    #[Groups(['user_preference:write'])]
    public ?bool $desktopNotifications;

    #[Groups(['user_preference:write'])]
    public ?bool $convertEmoticons;

    #[Groups(['user_preference:write'])]
    public ?bool $resumeLastLocation;

    /**
     * @var array<string, bool>|null
     */
    #[Assert\Count(max: 500)]
    #[Assert\All([new Assert\Type('bool')])]
    #[Groups(['user_preference:write'])]
    public ?array $sectionCollapse;

    #[Groups(['user_preference:write'])]
    public ?RemoteContentLevel $remoteContent;

    /**
     * @var list<string>|null
     */
    #[Assert\Count(max: 100)]
    #[Assert\All([
        new Assert\Type('string'),
        new Assert\Length(max: 253),
        new Assert\Regex('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', message: 'Each entry must be a bare host name such as example.com.'),
    ])]
    #[Groups(['user_preference:write'])]
    public ?array $remoteContentHosts;

    #[Groups(['user_preference:write'])]
    public ?bool $autoplayGifs;

    #[Groups(['user_preference:write'])]
    public ?bool $refusePlainDms;

    public function turnsOnPlainDmRefusal(): bool
    {
        return (new \ReflectionProperty($this, 'refusePlainDms'))->isInitialized($this) && true === $this->refusePlainDms;
    }

    public function changesPlainDmRefusal(bool $current): bool
    {
        return (new \ReflectionProperty($this, 'refusePlainDms'))->isInitialized($this) && (true === $this->refusePlainDms) !== $current;
    }

    #[\Override]
    public static function getEntityClass(): string
    {
        return UserPreference::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        \assert($entity instanceof UserPreference);

        if ((new \ReflectionProperty($this, 'theme'))->isInitialized($this)) {
            $entity->setTheme($this->theme);
        }
        if ((new \ReflectionProperty($this, 'submitKey'))->isInitialized($this)) {
            $entity->setSubmitKey($this->submitKey);
        }
        if ((new \ReflectionProperty($this, 'locale'))->isInitialized($this)) {
            $entity->setLocale($this->locale);
        }
        if ((new \ReflectionProperty($this, 'timezone'))->isInitialized($this)) {
            $entity->setTimezone($this->timezone);
        }
        if ((new \ReflectionProperty($this, 'sendTypingIndicator'))->isInitialized($this)) {
            $entity->setSendTypingIndicator($this->sendTypingIndicator);
        }
        if ((new \ReflectionProperty($this, 'desktopNotifications'))->isInitialized($this)) {
            $entity->setDesktopNotifications($this->desktopNotifications);
        }
        if ((new \ReflectionProperty($this, 'convertEmoticons'))->isInitialized($this)) {
            $entity->setConvertEmoticons($this->convertEmoticons);
        }
        if ((new \ReflectionProperty($this, 'resumeLastLocation'))->isInitialized($this)) {
            $entity->setResumeLastLocation($this->resumeLastLocation);
        }
        if ((new \ReflectionProperty($this, 'sectionCollapse'))->isInitialized($this)) {
            $entity->setSectionCollapse($this->sectionCollapse);
        }
        if ((new \ReflectionProperty($this, 'remoteContent'))->isInitialized($this)) {
            $entity->setRemoteContent($this->remoteContent);
        }
        if ((new \ReflectionProperty($this, 'remoteContentHosts'))->isInitialized($this)) {
            $entity->setRemoteContentHosts(null === $this->remoteContentHosts ? null : array_values(array_unique($this->remoteContentHosts)));
        }
        if ((new \ReflectionProperty($this, 'autoplayGifs'))->isInitialized($this)) {
            $entity->setAutoplayGifs($this->autoplayGifs);
        }
        if ((new \ReflectionProperty($this, 'refusePlainDms'))->isInitialized($this)) {
            $entity->setRefusePlainDms($this->refusePlainDms);
        }
    }
}
