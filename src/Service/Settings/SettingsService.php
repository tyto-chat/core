<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Dto\Admin\ServerConfigPatchDto;
use App\Entity\Setting;
use App\Entity\User;
use App\Enum\Admin\AdminAuditAction;
use App\Repository\SettingRepository;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\Security\SecretBoxInterface;
use App\Settings\SettingDef;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal direct EM access — sanctioned KV-store exemption
 */
final class SettingsService implements SettingsServiceInterface, ResetInterface
{
    /** @var array<string, Setting>|null Request/message-scoped cache of override rows; reset() clears it. */
    private ?array $keyed = null;

    public function __construct(
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly AdminAuditLoggerInterface $auditLogger,
        private readonly Security $security,
        private readonly SecretBoxInterface $secretBox,
    ) {
    }

    /**
     * @template T
     *
     * @param SettingDef<T> $def
     *
     * @return T
     */
    #[\Override]
    public function get(SettingDef $def): mixed
    {
        $rows = $this->loadKeyed();
        if (isset($rows[$def->key])) {
            /** @var T $decoded */
            $decoded = $def->type->decode($rows[$def->key]->getValue(), $def->enumClass);

            return $decoded;
        }

        return $def->default;
    }

    /** @param SettingDef<mixed> $def */
    #[\Override]
    public function has(SettingDef $def): bool
    {
        return isset($this->loadKeyed()[$def->key]);
    }

    #[\Override]
    public function isSmtpConfigured(): bool
    {
        return '' !== (string) $this->get(Settings::smtpHost());
    }

    #[\Override]
    public function applyPatch(ServerConfigPatchDto $dto): void
    {
        $rows = $this->loadKeyed();
        $actor = $this->security->getUser();
        $actor = $actor instanceof User ? $actor : null;
        $changes = [];

        foreach (Settings::all() as $def) {
            if ($def->secret) {
                continue;
            }
            if (!property_exists($dto, $def->key)) {
                continue;
            }
            $refl = new \ReflectionProperty($dto, $def->key);
            if (!$refl->isInitialized($dto)) {
                continue;
            }
            $value = $def->normalize($refl->getValue($dto));
            $old = isset($rows[$def->key])
                ? $def->type->decode($rows[$def->key]->getValue(), $def->enumClass)
                : $def->default;
            if ($old === $value) {
                continue;
            }
            $this->upsert($rows, $def, $def->type->encode($value), $actor);
            $changes[$def->key] = ['from' => $this->scalarize($old), 'to' => $this->scalarize($value)];
        }

        $pwRefl = new \ReflectionProperty($dto, 'smtpPassword');
        if ($pwRefl->isInitialized($dto)) {
            $pw = $pwRefl->getValue($dto);
            if (is_string($pw) && '' !== $pw) {
                $this->upsert($rows, Settings::smtpPassword(), $this->secretBox->encrypt($pw), $actor);
                $changes['smtpPassword'] = ['from' => '***', 'to' => '***'];
            }
        }

        if ([] !== $changes) {
            $this->em->flush();
            $this->auditLogger->record(AdminAuditAction::ServerConfigUpdate, 'server_config', null, ['changes' => $changes]);
            $this->keyed = null;
        }
    }

    #[\Override]
    public function completeAdminOnboarding(): void
    {
        if ($this->isAdminOnboarded()) {
            return;
        }
        $rows = $this->loadKeyed();
        $actor = $this->security->getUser();
        $this->upsert(
            $rows,
            Settings::adminOnboardedAt(),
            Settings::adminOnboardedAt()->type->encode(new \DateTimeImmutable()),
            $actor instanceof User ? $actor : null,
        );
        $this->em->flush();
        $this->keyed = null;
        $this->auditLogger->record(AdminAuditAction::BootstrapComplete, 'server_config', null, []);
    }

    #[\Override]
    public function isAdminOnboarded(): bool
    {
        return null !== $this->get(Settings::adminOnboardedAt());
    }

    /** @return array{at: ?\DateTimeImmutable, by: ?User} */
    #[\Override]
    public function lastUpdate(): array
    {
        $row = $this->repository->findMostRecentlyUpdated();

        return ['at' => $row?->getUpdatedAt(), 'by' => $row?->getUpdatedBy()];
    }

    /** @return array<string, Setting> */
    private function loadKeyed(): array
    {
        return $this->keyed ??= $this->repository->findAllKeyed();
    }

    #[\Override]
    public function reset(): void
    {
        $this->keyed = null;
    }

    /**
     * @param array<string, Setting> $rows by-ref so repeated upserts see prior inserts
     * @param SettingDef<mixed>      $def
     */
    private function upsert(array &$rows, SettingDef $def, mixed $encoded, ?User $actor): void
    {
        $row = $rows[$def->key] ?? null;
        if (null === $row) {
            $row = new Setting($def->key);
            $this->em->persist($row);
            $rows[$def->key] = $row;
        }
        $row->setValue($encoded)->touch($actor);
    }

    private function scalarize(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
