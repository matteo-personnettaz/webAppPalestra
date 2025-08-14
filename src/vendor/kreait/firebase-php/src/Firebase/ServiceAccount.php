<?php

declare(strict_types=1);

namespace Kreait\Firebase;

/**
 * @internal
 *
 * @todo Add #[SensitiveParameter] attributes once the minimum required PHP version is >=8.2
 */
final class ServiceAccount
{
    public function __construct(
        /** @var non-empty-string */
        public string $type,
        /** @var non-empty-string */
        public string $projectId,
        /** @var non-empty-string */
        public string $clientEmail,
        /** @var non-empty-string */
        public string $clientId,
        /** @var non-empty-string */
        public string $privateKey,
        /** @var non-empty-string */
        public string $privateKeyId,
        /** @var non-empty-string */
        public string $authUri,
        /** @var non-empty-string */
        public string $tokenUri,
        /** @var non-empty-string */
        public string $authProviderX509CertUrl,
        /** @var non-empty-string */
        public string $clientX509CertUrl,
        /** @var non-empty-string|null */
        public ?string $quotaProjectId = null,
        /** @var non-empty-string|null */
        public ?string $universeDomain = null,
    ) {
    }
}
