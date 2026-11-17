<?php

declare(strict_types=1);

namespace App\Library;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The secret part of the podcast feed address: podcast apps cannot log in, so the address itself
 * is the credential. It derives from APP_SECRET; changing the secret revokes every subscription.
 */
final readonly class FeedToken
{
    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {
    }

    public function value(): string
    {
        return substr(hash_hmac('sha256', 'crieur-podcast-feed', $this->secret), 0, 24);
    }

    public function matches(string $token): bool
    {
        return hash_equals($this->value(), $token);
    }
}
