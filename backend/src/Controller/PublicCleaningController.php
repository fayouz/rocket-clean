<?php

namespace App\Controller;

use App\Cleaning\CleaningLinkSigner;
use App\Cleaning\CleaningWork;
use App\Cleaning\LinenBridge;
use App\Linen\CleaningLinen;
use App\Cleaning\PublicRateLimiter;
use App\Entity\CleaningTask;
use App\Repository\CleaningTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Secret link of a cleaning, without account (/m/<token> in the interface, /api/public/cleaning/<token> here): the
 * cleaner runs that cleaning's checklist, sets its status and notes, adds photos and sets the place's stock levels (Rocket Place's), reports its linen (kits removed/placed),
 * and nothing else (no other cleaning, place, file or user). Rate-limited per IP, never cached nor indexed. The link
 * expires the day after the cleaning (CleaningLinkSigner::expiresAt), and an administrator can regenerate or revoke it.
 */
final class PublicCleaningController extends AbstractController
{
    private const TOKEN = '[A-Za-z0-9_-]{43}';

    public function __construct(
        private readonly CleaningTaskRepository $tasks,
        private readonly CleaningLinkSigner $signer,
        private readonly PublicRateLimiter $limiter,
        private readonly CleaningWork $work,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/public/cleaning/{token}', name: 'api_public_cleaning', methods: ['GET'], requirements: ['token' => self::TOKEN])]
    public function show(string $token, Request $request): JsonResponse
    {
        return $this->view($this->task($token, $request));
    }

    /** JSON, all optional: "status", "notes", "checklist": [{"index": int, "done": bool}]. Planning fields are ignored. */
    #[Route('/api/public/cleaning/{token}', name: 'api_public_cleaning_update', methods: ['PATCH'], requirements: ['token' => self::TOKEN])]
    public function update(string $token, Request $request): JsonResponse
    {
        $task = $this->task($token, $request);
        $body = $request->toArray();
        $this->work->apply($task, array_intersect_key($body, ['status' => 1, 'notes' => 1, 'checklist' => 1]));
        $this->em->flush();

        return $this->view($task);
    }

    /** Multipart: "file", "moment" (before|after|damage). */
    #[Route('/api/public/cleaning/{token}/photos', name: 'api_public_cleaning_photo', methods: ['POST'], requirements: ['token' => self::TOKEN])]
    public function photo(string $token, Request $request): JsonResponse
    {
        $task = $this->task($token, $request);
        $this->work->addPhoto($task, $request->files->get('file'), (string) $request->request->get('moment', 'after'));
        $this->em->flush();

        return $this->view($task, 201);
    }

    /** Content of one of this cleaning's photos ("file:<id>" as listed in "photos"). */
    #[Route('/api/public/cleaning/{token}/photos/{fileId}', name: 'api_public_cleaning_photo_content', methods: ['GET'], requirements: ['token' => self::TOKEN, 'fileId' => 'file:[A-Za-z0-9_-]{1,64}'])]
    public function photoContent(string $token, string $fileId, Request $request): Response
    {
        $content = $this->work->photoContent($this->task($token, $request), $fileId);

        return new Response($content, 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }

    /** JSON {"stockLevelId": uuid, "level": ok|low|empty}, a stock level of this cleaning's place. */
    #[Route('/api/public/cleaning/{token}/stock', name: 'api_public_cleaning_stock', methods: ['POST'], requirements: ['token' => self::TOKEN])]
    public function stock(string $token, Request $request): JsonResponse
    {
        $task = $this->task($token, $request);
        $this->work->setStock($task, $request->toArray());
        $this->em->flush();

        return $this->view($task);
    }

    /** The "Linge" step through the secret link: {kits, types, movements} of this cleaning. */
    #[Route('/api/public/cleaning/{token}/linen', name: 'api_public_cleaning_linen', methods: ['GET'], requirements: ['token' => self::TOKEN])]
    public function linen(string $token, Request $request, CleaningLinen $linen): JsonResponse
    {
        return $this->noStore($this->json($linen->view(LinenBridge::job($this->task($token, $request)))));
    }

    /** Same body as POST /api/cleanings/{id}/linen ("key" makes an offline replay idempotent). */
    #[Route('/api/public/cleaning/{token}/linen', name: 'api_public_cleaning_linen_record', methods: ['POST'], requirements: ['token' => self::TOKEN])]
    public function recordLinen(string $token, Request $request, CleaningLinen $linen): JsonResponse
    {
        $job = LinenBridge::job($this->task($token, $request));
        $result = $linen->record($job, $request->toArray(), null);
        $this->em->flush();

        return $this->noStore($this->json($result + $linen->view($job), $result['alreadyRecorded'] ? 200 : 201));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function task(string $token, Request $request): CleaningTask
    {
        $this->limiter->hit($request);
        $id = CleaningLinkSigner::parse($token);
        $task = null === $id ? null : $this->tasks->find($id);
        if (null === $task || !$this->signer->verify($task, $token) || CleaningTask::CANCELLED === $task->getStatus()) {
            $this->limiter->failure($request);
            throw new HttpException(404, 'Lien de ménage invalide ou expiré.');
        }

        return $task;
    }

    private function view(CleaningTask $task, int $status = 200): JsonResponse
    {
        $data = $task->toArray();
        // Only what the cleaner needs: no external reference, no assignee e-mail.
        unset($data['externalRef']);
        $data['assignee'] = null === $data['assignee'] ? null : ['name' => $data['assignee']['name']];
        $data['stock'] = $this->work->stockView($task);
        $data['expiresAt'] = CleaningLinkSigner::expiresAt($task)->format(\DATE_ATOM);
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
