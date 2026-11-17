<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Segment;
use App\Enum\ArticleSource;
use App\Enum\ArticleStatus;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Form\ArticleRequest;
use App\Form\ArticleType;
use App\Library\ArticleQueue;
use App\Repository\ArticleRepository;
use App\Speech\AudioStore;
use App\Twig\Formatting;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One article: added from the home page, listened to with its read-along transcript,
 * and read again with another voice or language.
 */
final class ArticleController extends AbstractController
{
    /**
     * The CSRF token id of every form of the interface: stateless, checked against the origin
     * of the request and the double-submit cookie (config/packages/csrf.yaml).
     */
    public const string CSRF_TOKEN_ID = 'submit';

    public function __construct(
        private readonly ArticleQueue $queue,
        private readonly AudioStore $audio,
    ) {
    }

    #[Route('/articles', name: 'article_create', methods: ['POST'])]
    public function create(Request $request, ArticleRepository $articles): Response
    {
        $data = new ArticleRequest();
        $form = $this->createForm(ArticleType::class, $data, ['action' => $this->generateUrl('article_create')]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $article = $this->queue->queue((string) $data->url, ArticleSource::Web, $data->language, $data->voice);

            // "play" asks the read-along page to start the audio as soon as the first chunk is ready.
            return $this->redirectToRoute('article_show', ['id' => $article->getId(), 'play' => 1], Response::HTTP_SEE_OTHER);
        }

        // Turbo renders a form response only with a 4xx status: 422 also when nothing was submitted.
        return $this->render('home/index.html.twig', [
            'form' => $form,
            'articles' => $articles->latest(),
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/articles/{id}', name: 'article_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] Article $article, Request $request): Response
    {
        return $this->render('article/show.html.twig', [
            'article' => $article,
            'autoplay' => $request->query->getBoolean('play'),
        ]);
    }

    #[Route('/articles/{id}/status', name: 'article_status', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function status(#[MapEntity(id: 'id')] Article $article, TranslatorInterface $translator): JsonResponse
    {
        $segments = [];
        foreach ($article->getSegments() as $segment) {
            $key = $segment->getAudioKey();
            $segments[] = [
                'position' => $segment->getPosition(),
                'paragraph' => $segment->getParagraph(),
                'ready' => null !== $key,
                'url' => null === $key ? null : $this->generateUrl('segment_audio', [
                    'id' => $article->getId(),
                    'position' => $segment->getPosition(),
                    'k' => $key,
                ]),
                'durationMs' => $segment->getDurationMs(),
                'pauseAfterMs' => $segment->getPauseAfterMs(),
                'chars' => mb_strlen($segment->getSpoken()),
                'edited' => $segment->isEdited(),
                'spokenHash' => Formatting::spokenHash($segment->getSpoken()),
            ];
        }

        $failure = $article->getFailure();
        $response = new JsonResponse([
            'id' => $article->getId()->toRfc4122(),
            'status' => $article->getStatus()->value,
            'inProgress' => $article->getStatus()->isInProgress(),
            'playable' => $article->getStatus()->isPlayable(),
            'failure' => $failure,
            'failureMessage' => null === $failure ? null : $translator->trans($failure, [], 'errors'),
            'title' => $article->getDisplayTitle(),
            'site' => $article->getSiteName() ?? $article->getHost(),
            'url' => $article->getUrl(),
            'page' => $this->generateUrl('article_show', ['id' => $article->getId()]),
            'language' => $article->getLanguage()?->value,
            'languageSetting' => $article->getLanguageSetting()->value,
            'voice' => $article->getVoiceName(),
            'voiceGender' => $article->getVoiceGender()->value,
            'editor' => $article->getEditor(),
            'paragraphCount' => $article->getParagraphCount(),
            'revision' => $article->getRevision(),
            'readySegments' => $article->countReadySegments(),
            'audioSeconds' => round($article->getAudioSeconds(), 1),
            'segments' => $segments,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * The audio of one chunk. Its address carries the audio key ("k"): the same address always
     * gives the same audio, so browsers may keep it for good. A stale key answers 404.
     */
    #[Route(
        '/articles/{id}/segments/{position}.wav',
        name: 'segment_audio',
        requirements: ['id' => Requirement::UUID, 'position' => Requirement::DIGITS],
        methods: ['GET', 'HEAD'],
    )]
    public function audio(#[MapEntity(id: 'id')] Article $article, int $position, Request $request): BinaryFileResponse
    {
        $key = $this->segment($article, $position)?->getAudioKey();
        $requested = $request->query->getString('k');
        if (null === $key || ('' !== $requested && $requested !== $key)) {
            throw new NotFoundHttpException('This audio does not exist, or not anymore.');
        }

        $path = $this->audio->path($key);
        if (!is_file($path)) {
            throw new NotFoundHttpException('The audio file is missing.');
        }

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'audio/wav'], autoEtag: true, autoLastModified: true);
        if ('' === $requested) {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-cache');
        } else {
            $response->setPublic();
            $response->setMaxAge(31_536_000);
            $response->setImmutable();
        }

        return $response;
    }

    #[Route('/articles/{id}/voice', name: 'article_voice', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function voice(#[MapEntity(id: 'id')] Article $article, Request $request): Response
    {
        $this->denyUnlessValidCsrf($request);
        $gender = VoiceGender::tryFrom($request->getPayload()->getString('voice'))
            ?? throw new BadRequestHttpException('Expected the voice "female" or "male".');

        $this->queue->changeVoice($article, $gender);

        return $this->backToArticle($article);
    }

    #[Route('/articles/{id}/language', name: 'article_language', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function language(#[MapEntity(id: 'id')] Article $article, Request $request): Response
    {
        $this->denyUnlessValidCsrf($request);
        $setting = LanguageSetting::tryFrom($request->getPayload()->getString('language'))
            ?? throw new BadRequestHttpException('Expected the language "auto", "fr" or "en".');

        if ($setting !== $article->getLanguageSetting()) {
            $this->queue->restart($article, $setting);
        }

        return $this->backToArticle($article);
    }

    #[Route('/articles/{id}/retry', name: 'article_retry', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function retry(#[MapEntity(id: 'id')] Article $article, Request $request): Response
    {
        $this->denyUnlessValidCsrf($request);
        if (ArticleStatus::Failed === $article->getStatus()) {
            $this->queue->restart($article);
        }

        return $this->backToArticle($article);
    }

    #[Route('/articles/{id}/delete', name: 'article_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(#[MapEntity(id: 'id')] Article $article, Request $request): Response
    {
        $this->denyUnlessValidCsrf($request);
        $this->queue->remove($article);

        return $this->redirectToRoute('home', status: Response::HTTP_SEE_OTHER);
    }

    private function segment(Article $article, int $position): ?Segment
    {
        foreach ($article->getSegments() as $segment) {
            if ($segment->getPosition() === $position) {
                return $segment;
            }
        }

        return null;
    }

    private function denyUnlessValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('The form has expired or comes from another site: reload the page and try again.');
        }
    }

    private function backToArticle(Article $article): Response
    {
        return $this->redirectToRoute('article_show', ['id' => $article->getId()], Response::HTTP_SEE_OTHER);
    }
}
