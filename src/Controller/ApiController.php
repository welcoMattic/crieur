<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\BrowserArticle;
use App\Demo\TooManyArticles;
use App\Editor\SpeechEditor;
use App\Entity\Article;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Extraction\ExtractedArticle;
use App\Extraction\ExtractionFailed;
use App\Extraction\TitleCleaner;
use App\Library\ArticleQueue;
use App\Library\InvalidArticleUrl;
use App\Speech\VoiceCatalog;
use App\Text\NoReadableText;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The API of the browser extension: it sends the article it extracted from the page the reader
 * sees, and the server prepares it at once, without fetching the page.
 *
 * Who may call it: with CRIEUR_API_TOKEN set, a request needs "Authorization: Bearer <token>".
 * Always, a request coming from another web page (an http or https Origin other than this site)
 * is refused, and the body must be JSON: a page cannot send JSON across sites without a CORS
 * preflight, which this API never grants.
 */
#[Route('/api')]
final class ApiController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'CRIEUR_API_TOKEN')]
        private readonly string $apiToken,
    ) {
    }

    #[Route('/ping', name: 'api_ping', methods: ['GET'])]
    public function ping(Request $request, VoiceCatalog $voices, SpeechEditor $editor): JsonResponse
    {
        if (null !== $refusal = $this->refusal($request)) {
            return $refusal;
        }

        return new JsonResponse([
            'name' => 'Crieur',
            'tokenRequired' => '' !== trim($this->apiToken),
            'voiceEngine' => $voices->defaultEngine()->name,
            'editor' => $editor->model(),
        ]);
    }

    #[Route('/articles', name: 'api_article_create', methods: ['POST'])]
    public function create(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')]
        BrowserArticle $payload,
        ArticleQueue $queue,
        TranslatorInterface $translator,
        ClockInterface $clock,
    ): JsonResponse {
        if (null !== $refusal = $this->refusal($request)) {
            return $refusal;
        }

        $page = new ExtractedArticle(
            url: $payload->url,
            title: TitleCleaner::clean($payload->title, self::clean($payload->siteName), $payload->url),
            html: $payload->content,
            siteName: self::clean($payload->siteName),
            author: self::clean($payload->byline),
            excerpt: self::clean($payload->excerpt),
            imageUrl: $payload->image,
            languageHint: self::clean($payload->lang),
            publishedAt: self::date($payload->publishedTime),
        );

        try {
            $article = $queue->queueFromBrowser($page, LanguageSetting::from($payload->language), VoiceGender::from($payload->voice));
        } catch (InvalidArticleUrl $e) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'url.'.$e->getMessage(), $translator->trans('url.'.$e->getMessage(), [], 'errors'));
        } catch (TooManyArticles $e) {
            $response = $this->error(Response::HTTP_TOO_MANY_REQUESTS, 'demo.too_many', $translator->trans('demo.too_many', [], 'errors'));
            $response->headers->set('Retry-After', (string) $e->retryAfterSeconds($clock->now()));

            return $response;
        } catch (ExtractionFailed $e) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, $e->reason, $translator->trans($e->reason, [], 'errors'));
        } catch (NoReadableText) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, ExtractionFailed::NO_TEXT, $translator->trans(ExtractionFailed::NO_TEXT, [], 'errors'));
        }

        return new JsonResponse($this->describe($article), Response::HTTP_CREATED);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Article $article): array
    {
        return [
            'id' => $article->getId()->toRfc4122(),
            'status' => $article->getStatus()->value,
            'title' => $article->getTitle(),
            'language' => $article->getLanguage()?->value,
            'voice' => $article->getVoiceName(),
            'listenUrl' => $this->generateUrl('article_show', ['id' => $article->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    private function refusal(Request $request): ?JsonResponse
    {
        $origin = $request->headers->get('Origin');
        if (null !== $origin && 1 === preg_match('~^https?://~i', $origin) && rtrim($origin, '/') !== $request->getSchemeAndHttpHost()) {
            return $this->error(Response::HTTP_FORBIDDEN, 'cross_site', 'Requests from other web pages are refused.');
        }

        if ('' !== trim($this->apiToken)) {
            $given = (string) preg_replace('/^Bearer\s+/i', '', (string) $request->headers->get('Authorization'));
            if (!hash_equals(trim($this->apiToken), trim($given))) {
                return $this->error(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'This Crieur server needs its API token (CRIEUR_API_TOKEN) in the options of the extension.');
            }
        }

        return null;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message], $status);
    }

    private static function clean(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' === $value ? null : $value;
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
