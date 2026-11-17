<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventListener\PreferredLocaleListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Switches the language of the interface and remembers it in a cookie for a year.
 */
final class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'locale_switch', requirements: ['locale' => 'en|fr'], methods: ['GET'])]
    public function switch(string $locale, Request $request): RedirectResponse
    {
        $response = $this->redirect(self::localPath($request->query->getString('return')) ?? $this->generateUrl('home'));
        $response->headers->setCookie(Cookie::create(
            PreferredLocaleListener::COOKIE,
            $locale,
            new \DateTimeImmutable('+1 year'),
            secure: $request->isSecure(),
            sameSite: Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    /**
     * Only a path of this site: never another host ("//evil.example") nor a scheme.
     */
    private static function localPath(string $path): ?string
    {
        return 1 === preg_match('~^/(?![/\\\\])[^\s]*$~', $path) ? $path : null;
    }
}
