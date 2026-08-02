<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Enum\Legal\LegalDocumentType;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\SettingDef;
use App\Settings\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LegalDocumentService implements LegalDocumentServiceInterface
{
    public function __construct(
        private readonly SettingsServiceInterface $settings,
        #[Autowire('%kernel.project_dir%/resources/legal')] private readonly string $legalDir,
    ) {
    }

    #[\Override]
    public function getContent(LegalDocumentType $type): string
    {
        $override = $this->getRawOverride($type);

        return $this->interpolate('' !== $override ? $override : $this->rawDefault($type));
    }

    #[\Override]
    public function getDefault(LegalDocumentType $type): string
    {
        return $this->interpolate($this->rawDefault($type));
    }

    #[\Override]
    public function getRawOverride(LegalDocumentType $type): string
    {
        return trim((string) $this->settings->get($this->setting($type)));
    }

    #[\Override]
    public function isCustomized(LegalDocumentType $type): bool
    {
        return '' !== $this->getRawOverride($type);
    }

    /** @return SettingDef<string> */
    private function setting(LegalDocumentType $type): SettingDef
    {
        return match ($type) {
            LegalDocumentType::Terms => Settings::termsContent(),
            LegalDocumentType::Privacy => Settings::privacyContent(),
        };
    }

    private function rawDefault(LegalDocumentType $type): string
    {
        $path = $this->legalDir.'/'.$type->value.'.md';
        $content = is_file($path) ? file_get_contents($path) : false;

        return false !== $content ? $content : '';
    }

    private function interpolate(string $markdown): string
    {
        $contact = trim((string) $this->settings->get(Settings::legalContactEmail()));

        return strtr($markdown, [
            '%server_name%' => $this->settings->get(Settings::serverName()),
            '%contact_email%' => '' !== $contact ? $contact : 'the server administrator',
            '%updated_at%' => (new \DateTimeImmutable())->format('Y-m-d'),
        ]);
    }
}
