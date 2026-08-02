<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Utils\ApiVersions;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator('api_platform.openapi.factory', priority: -10)]
final readonly class PlainRoutesDocumentationFactory implements OpenApiFactoryInterface
{
    public function __construct(
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = $this->decorated->__invoke($context);
        $paths = $openApi->getPaths();
        $v = '/api/'.ApiVersions::CANONICAL;

        $paths->addPath('/auth', new PathItem(post: new Operation(
            operationId: 'postAuth',
            tags: ['Authentication'],
            responses: [
                '200' => $this->jsonResponse('JWT issued. The token is also set as an httpOnly `BEARER` cookie, and a rotating `refresh_token` httpOnly cookie is attached. When the account has two-factor authentication enabled, the response is instead {"twoFactorRequired": true, "token": "<short-lived pending token>"} and no cookies are set — complete the login via /auth/2fa.', [
                    'type' => 'object',
                    'properties' => ['token' => ['type' => 'string']],
                ]),
                '401' => new Response('Invalid credentials or server-banned account.'),
            ],
            summary: 'Log in with email and password',
            description: 'Anonymous. Exchanges credentials for a JWT access token used as `Authorization: Bearer <jwt>`. Returns 401 for wrong credentials and for server-banned accounts.',
            requestBody: new RequestBody(
                description: 'User credentials.',
                content: new \ArrayObject(['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['email', 'password'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'password' => ['type' => 'string', 'format' => 'password'],
                    ],
                ]]]),
                required: true,
            ),
            security: [],
        )));

        $paths->addPath('/auth/2fa', new PathItem(post: new Operation(
            operationId: 'postAuth2fa',
            tags: ['Authentication'],
            responses: [
                '200' => $this->jsonResponse('Two-factor verification succeeded. Same response as /auth: JWT issued, httpOnly `BEARER` and rotating `refresh_token` cookies attached.', [
                    'type' => 'object',
                    'properties' => ['token' => ['type' => 'string']],
                ]),
                '401' => new Response('Missing/expired pending token or invalid code.'),
                '429' => new Response('Too many attempts.'),
            ],
            summary: 'Complete a two-factor login',
            description: 'Requires the short-lived pending token from /auth (`Authorization: Bearer <pending>`). Accepts a 6-digit TOTP code or a single-use recovery code. Optional `remember_me` mirrors /auth.',
            requestBody: new RequestBody(
                description: 'Verification code.',
                content: new \ArrayObject(['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'remember_me' => ['type' => 'boolean'],
                    ],
                ]]]),
                required: true,
            ),
            security: [],
        )));

        $paths->addPath('/token/refresh', new PathItem(post: new Operation(
            operationId: 'postTokenRefresh',
            tags: ['Authentication'],
            responses: [
                '200' => $this->jsonResponse('New JWT issued; the single-use refresh token is rotated in the `refresh_token` cookie.', [
                    'type' => 'object',
                    'properties' => ['token' => ['type' => 'string']],
                ]),
                '401' => new Response('Missing, invalid or expired refresh token.'),
            ],
            summary: 'Refresh the access token',
            description: 'Anonymous. Reads the httpOnly `refresh_token` cookie set at login and issues a fresh JWT. Refresh tokens are single-use with a 30-day sliding expiry; an invalid or reused token yields 401.',
            security: [],
        )));

        $paths->addPath('/logout', new PathItem(post: new Operation(
            operationId: 'postLogout',
            tags: ['Authentication'],
            responses: [
                '204' => new Response('Refresh token revoked and auth cookies cleared.'),
            ],
            summary: 'Log out and revoke the refresh token',
            description: 'Anonymous. Deletes the refresh token referenced by the `refresh_token` cookie and clears the `BEARER`, `refresh_token` and remember-me cookies. Always returns 204.',
            security: [],
        )));

        $paths->addPath('/api/health', new PathItem(get: new Operation(
            operationId: 'getHealth',
            tags: ['Health'],
            responses: [
                '200' => $this->jsonResponse('Service is up.', [
                    'type' => 'object',
                    'properties' => ['status' => ['type' => 'string', 'enum' => ['ok']]],
                ]),
                '503' => $this->jsonResponse('At least one subsystem is down.', [
                    'type' => 'object',
                    'properties' => ['status' => ['type' => 'string', 'enum' => ['down']]],
                ]),
            ],
            summary: 'Check public service liveness',
            description: 'Anonymous liveness probe for load balancers and uptime monitors. The body is deliberately opaque — only the status code matters; the aggregated verdict is cached for a few seconds.',
            security: [],
        )));

        $paths->addPath('/api/versions', new PathItem(get: new Operation(
            operationId: 'getVersions',
            tags: ['Versioning'],
            responses: [
                '200' => $this->jsonResponse('Supported API versions and the per-feature version map.', [
                    'type' => 'object',
                    'properties' => [
                        'versions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'features' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ],
                ]),
            ],
            summary: 'Discover supported API versions',
            description: 'Anonymous version discovery, deliberately outside /api/{version}. The response shape is frozen: keys may be added to `features`, never removed or renamed. Cacheable (max-age 60).',
            security: [],
        )));

        $exportStatusSchema = [
            'type' => 'object',
            'properties' => [
                'pending' => ['type' => 'boolean'],
                'id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
                'requestedAt' => ['type' => 'string', 'format' => 'date-time'],
                'readyAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'expiresAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'fileSize' => ['type' => 'integer', 'nullable' => true],
                'downloadUrl' => ['type' => 'string', 'nullable' => true],
            ],
        ];

        $paths->addPath($v.'/me/data-export', new PathItem(
            get: new Operation(
                operationId: 'getMeDataExport',
                tags: ['DataExport'],
                responses: [
                    '200' => $this->jsonResponse('Current export state; `{"pending": false}` when none is active.', $exportStatusSchema),
                    '401' => new Response('Not authenticated.'),
                ],
                summary: 'Get the status of my data export',
                description: 'Authenticated users only. Returns the caller\'s active GDPR export request with a signed `downloadUrl` once ready, or `{"pending": false}` when no export is in progress.',
            ),
            post: new Operation(
                operationId: 'postMeDataExport',
                tags: ['DataExport'],
                responses: [
                    '201' => $this->jsonResponse('Export request accepted and queued.', $exportStatusSchema),
                    '401' => new Response('Not authenticated.'),
                    '409' => new Response('An export is already in progress.'),
                    '429' => new Response('Cooldown since the last export has not elapsed; `Retry-After` header indicates when to retry.'),
                ],
                summary: 'Request an export of my personal data',
                description: 'Authenticated users only. Queues an asynchronous GDPR export of the caller\'s data. Rejected with 409 while a previous request is pending and with 429 during the per-user cooldown window.',
            ),
        ));

        $paths->addPath($v.'/me/data-export/download', new PathItem(get: new Operation(
            operationId: 'getMeDataExportDownload',
            tags: ['DataExport'],
            responses: [
                '200' => new Response('The export archive as an attachment.', new \ArrayObject([
                    'application/zip' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                ])),
                '401' => new Response('Not authenticated.'),
                '404' => new Response('Token invalid, export not ready, download window elapsed, or file missing.'),
            ],
            summary: 'Download my ready data export',
            description: 'Authenticated users only; the signed token must belong to the caller\'s own ready export. Streams the archive as a file attachment; any invalid or expired token yields 404.',
            parameters: [new Parameter(
                name: 'token',
                in: 'query',
                description: 'Opaque signed download token from the export status response.',
                required: true,
                schema: ['type' => 'string'],
            )],
        )));

        $paths->addPath($v.'/admin/setup-status', new PathItem(get: new Operation(
            operationId: 'getAdminSetupStatus',
            tags: ['Admin'],
            responses: [
                '200' => $this->jsonResponse('Per-item setup completion flags.', [
                    'type' => 'object',
                    'additionalProperties' => true,
                ]),
                '401' => new Response('Not authenticated.'),
                '403' => new Response('Not a server administrator.'),
            ],
            summary: 'Get server setup completion status',
            description: 'Server administrators only. Reports which first-run configuration items (SMTP, bot user, realtime, search) are still incomplete, backing the admin attention banner. Kept off the anonymous server-info endpoint so incomplete configuration is never leaked.',
        )));

        $paths->addPath($v.'/admin/health', new PathItem(get: new Operation(
            operationId: 'getAdminHealth',
            tags: ['Admin'],
            responses: [
                '200' => $this->jsonResponse('Overall verdict, per-dependency probe results and system resource metrics.', [
                    'type' => 'object',
                    'properties' => [
                        'overall' => ['type' => 'string'],
                        'checks' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'system' => ['type' => 'object'],
                    ],
                ]),
                '401' => new Response('Not authenticated.'),
                '403' => new Response('Not a server administrator.'),
            ],
            summary: 'Get detailed health probes and system metrics',
            description: 'Server administrators only. Runs every dependency probe (database, Redis, Meilisearch, Mercure) and returns per-probe status plus CPU, memory and disk metrics. The anonymous counterpart at /api/health exposes only an opaque up/down verdict.',
        )));

        return $openApi;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function jsonResponse(string $description, array $schema): Response
    {
        return new Response($description, new \ArrayObject([
            'application/json' => ['schema' => $schema],
        ]));
    }
}
