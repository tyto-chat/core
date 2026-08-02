<?php

declare(strict_types=1);

namespace App\State\Legal\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\LegalDocument;
use App\Enum\Legal\LegalDocumentType;
use App\Service\Legal\LegalDocumentServiceInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<LegalDocument>
 */
final readonly class LegalDocumentProvider implements ProviderInterface
{
    public function __construct(
        private LegalDocumentServiceInterface $legalDocumentService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LegalDocument
    {
        $type = LegalDocumentType::tryFrom((string) ($uriVariables['type'] ?? ''));
        if (null === $type) {
            throw new NotFoundHttpException('Unknown legal document.');
        }

        $request = $context['request'] ?? null;
        $wantsDefault = 'default' === $request?->query->get('variant');

        $dto = new LegalDocument();
        $dto->type = $type->value;
        $dto->content = $wantsDefault
            ? $this->legalDocumentService->getDefault($type)
            : $this->legalDocumentService->getContent($type);
        $dto->customized = $this->legalDocumentService->isCustomized($type);

        return $dto;
    }
}
