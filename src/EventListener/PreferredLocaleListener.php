<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the language chosen with the switch of the interface, kept in a cookie (not in the
 * session: a session would make every response private, the audio of the chunks included).
 *
 * Runs after the router (32) and before the locale listener of Symfony (16), which reads the
 * "_locale" attribute, else the Accept-Language header (framework.set_locale_from_accept_language).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final readonly class PreferredLocaleListener
{
    public const string COOKIE = 'crieur_locale';

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        #[Autowire(param: 'kernel.enabled_locales')]
        private array $enabledLocales,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $locale = $request->cookies->getString(self::COOKIE);
        if ('' !== $locale && !$request->attributes->has('_locale') && \in_array($locale, $this->enabledLocales, true)) {
            $request->attributes->set('_locale', $locale);
        }
    }
}
