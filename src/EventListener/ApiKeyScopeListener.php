<?php

declare(strict_types=1);

namespace App\EventListener;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Security\ApiKeyScopeGuard;
use App\Security\Attribute\RequiresScope;
use App\Security\Authentication\ApiKeyAuthenticator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER, priority: -5)]
class ApiKeyScopeListener
{
    public function __construct(
        private readonly ?ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory = null,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $granted = $request->attributes->get(ApiKeyAuthenticator::REQUEST_SCOPES_ATTRIBUTE);
        if (!is_array($granted)) {
            return;
        }

        $required = $this->extractRequiredScopes($event->getController(), $request);
        if ([] === $required) {
            throw new HttpException(403, 'This endpoint does not declare an API key scope; access via Personal Access Token is not permitted.', null, ['WWW-Authenticate' => 'Bearer error="insufficient_scope"']);
        }

        foreach ($required as $scope) {
            if (!in_array($scope, $granted, true)) {
                throw ApiKeyScopeGuard::insufficientScopeException($scope);
            }
        }
    }

    /**
     * @param callable|object|array{0: object, 1: string}|string $controller
     *
     * @return list<string>
     */
    private function extractRequiredScopes(callable|object|array|string $controller, Request $request): array
    {
        $method = $request->getMethod();

        // API Platform sets only `_api_operation_name` + `_api_resource_class`, never `_api_operation` — must resolve via the metadata factory.
        $op = $this->resolveApiOperation($request);
        if ($op instanceof HttpOperation) {
            $extra = $op->getExtraProperties();
            if (isset($extra['scope']) && is_string($extra['scope'])) {
                return [$extra['scope']];
            }
            if (isset($extra['scopeResource']) && is_string($extra['scopeResource'])) {
                return [$extra['scopeResource'].':'.self::action($method)];
            }
        }

        if (is_array($controller) && 2 === count($controller) && is_object($controller[0]) && is_string($controller[1])) {
            $reflMethod = new \ReflectionMethod($controller[0], $controller[1]);
            $attrs = $reflMethod->getAttributes(RequiresScope::class);
            if ([] === $attrs) {
                $attrs = $reflMethod->getDeclaringClass()->getAttributes(RequiresScope::class);
            }
            if ([] !== $attrs) {
                $scopes = [];
                foreach ($attrs as $attr) {
                    /** @var RequiresScope $inst */
                    $inst = $attr->newInstance();
                    $scopes[] = null !== $inst->scope
                        ? $inst->scope
                        : ($inst->resource.':'.self::action($method));
                }

                return array_values(array_unique($scopes));
            }
        }

        return [];
    }

    private static function action(string $httpMethod): string
    {
        return 'GET' === $httpMethod || 'HEAD' === $httpMethod ? 'read' : 'write';
    }

    private function resolveApiOperation(Request $request): ?HttpOperation
    {
        $existing = $request->attributes->get('_api_operation');
        if ($existing instanceof HttpOperation) {
            return $existing;
        }
        if (null === $this->resourceMetadataCollectionFactory) {
            return null;
        }
        $resourceClass = $request->attributes->get('_api_resource_class');
        $operationName = $request->attributes->get('_api_operation_name');
        if (!is_string($resourceClass) || !is_string($operationName)) {
            return null;
        }
        try {
            $op = $this->resourceMetadataCollectionFactory->create($resourceClass)->getOperation($operationName);
        } catch (\Throwable) {
            return null;
        }

        return $op instanceof HttpOperation ? $op : null;
    }
}
