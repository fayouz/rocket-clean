<?php

namespace App\Controller;

use App\Cleaning\CleaningLinkSigner;
use App\Cleaning\CleaningNotifier;
use App\Cleaning\CleaningSettings;
use App\Cleaning\CleaningWork;
use App\Entity\CleaningChecklistItem;
use App\Entity\CleaningTask;
use App\Repository\CleaningChecklistItemRepository;
use App\Repository\CleaningTaskRepository;
use App\Place\PlaceDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Cleanings ("ménage") of places. Planning (create, reschedule, assign, delete) and the checklist template are
 * CLEAN_MANAGE (an administrator, or a client application such as a PMS acting for itself); carrying a cleaning out
 * (status, checklist, notes, photos, stock) is open to its assignee — or anyone when unassigned — and to managers.
 */
#[IsGranted('CLEAN_READ')]
final class CleaningController extends AbstractController
{
    public function __construct(
        private readonly CleaningTaskRepository $tasks,
        private readonly CleaningChecklistItemRepository $checklistItems,
        private readonly CleaningWork $work,
        private readonly CleaningNotifier $notifier,
        private readonly CleaningSettings $settings,
        private readonly PlaceDirectory $places,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Query: date=YYYY-MM-DD (default today, "all" for no date filter), mine=1, place=<place id>. Late open tasks are included with a date. */
    #[Route('/api/cleanings', name: 'api_cleanings', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        [$from, $to] = $this->day((string) $request->query->get('date', ''));
        $placeId = (string) $request->query->get('place', '');
        if ('' !== $placeId && !Uuid::isValid($placeId)) {
            throw new HttpException(404, 'Lieu inconnu.');
        }
        $assignee = null;
        if ($request->query->getBoolean('mine')) {
            $assignee = $this->getUser() instanceof User ? $this->getUser() : throw new HttpException(400, '« mine » demande un utilisateur.');
        }
        $now = new \DateTimeImmutable();

        return $this->json(array_map(static fn (CleaningTask $t) => $t->toArray($now), $this->tasks->search($from, $to, '' === $placeId ? null : $placeId, $assignee, null !== $from)));
    }

    /** Cleanings of a place (known locally; the place itself is not looked up, so a PMS can read them cheaply). */
    #[Route('/api/places/{placeId}/cleanings', name: 'api_place_cleanings', methods: ['GET'], requirements: ['placeId' => Requirement::UUID])]
    public function listForPlace(string $placeId): JsonResponse
    {
        $now = new \DateTimeImmutable();

        return $this->json(array_map(static fn (CleaningTask $t) => $t->toArray($now), $this->tasks->search(null, null, $placeId)));
    }

    /**
     * JSON {"scheduledAt": ISO-8601, "dueAt"?: ISO-8601|null, "label"?: string, "assigneeEmail"?|"assigneeId"?: string,
     * "notes"?: string, "externalRef"?: string}. Find-or-create by externalRef: 200 with the existing task (unchanged).
     */
    #[Route('/api/places/{placeId}/cleanings', name: 'api_place_cleanings_create', methods: ['POST'], requirements: ['placeId' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function create(string $placeId, Request $request): JsonResponse
    {
        $body = $request->toArray();
        $externalRef = null === ($body['externalRef'] ?? null) ? null : mb_substr(trim((string) $body['externalRef']), 0, 120);
        if (null !== $externalRef && null !== ($existing = $this->tasks->findOneBy(['placeId' => $placeId, 'externalRef' => $externalRef]))) {
            return $this->json($existing->toArray());
        }
        $site = $this->places->get($placeId);
        $label = trim((string) ($body['label'] ?? '')) ?: 'Ménage';
        $task = new CleaningTask($site->getId(), $site->getName(), mb_substr($label, 0, 120), $this->date($body['scheduledAt'] ?? null) ?? throw new HttpException(422, 'scheduledAt requis.'), $this->checklistView($placeId), $externalRef);
        $this->applyPlanning($task, $body);
        $this->em->persist($task);
        $this->em->flush();
        $this->notifyAssignee($task, null);

        return $this->json($task->toArray(), 201);
    }

    #[Route('/api/cleanings/{id}', name: 'api_cleaning', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity] CleaningTask $task): JsonResponse
    {
        return $this->json($task->toArray());
    }

    /**
     * JSON, all optional. Anyone doing the cleaning: "status", "notes", "checklist": [{"index": int, "done": bool}].
     * Managers only: "label", "scheduledAt", "dueAt", "assigneeEmail"/"assigneeId" (null to unassign).
     */
    #[Route('/api/cleanings/{id}', name: 'api_cleaning_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    public function update(#[MapEntity] CleaningTask $task, Request $request): JsonResponse
    {
        $this->assertCanWork($task);
        $body = $request->toArray();
        $previousAssignee = $task->getAssignee();
        if (array_intersect(['label', 'scheduledAt', 'dueAt', 'assigneeEmail', 'assigneeId'], array_keys($body))) {
            $this->denyAccessUnlessGranted('CLEAN_MANAGE');
            if (isset($body['label']) && '' !== trim((string) $body['label'])) {
                $task->setLabel(mb_substr(trim((string) $body['label']), 0, 120));
            }
            if (isset($body['scheduledAt'])) {
                $task->setScheduledAt($this->date($body['scheduledAt']) ?? throw new HttpException(422, 'scheduledAt invalide.'));
            }
            $this->applyPlanning($task, $body);
        }
        $this->work->apply($task, $body);
        $this->em->flush();
        $this->notifyAssignee($task, $previousAssignee);

        return $this->json($task->toArray());
    }

    #[Route('/api/cleanings/{id}', name: 'api_cleaning_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function delete(#[MapEntity] CleaningTask $task): JsonResponse
    {
        $this->em->remove($task);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** Multipart: "file" (jpeg/png/webp/heic, 15 Mo max), "moment" (before|after|damage). Stored in the place's Rocket Cloud folder. */
    #[Route('/api/cleanings/{id}/photos', name: 'api_cleaning_photo', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function photo(#[MapEntity] CleaningTask $task, Request $request): JsonResponse
    {
        $this->assertCanWork($task);
        $this->work->addPhoto($task, $request->files->get('file'), (string) $request->request->get('moment', 'after'));
        $this->em->flush();

        return $this->json($task->toArray(), 201);
    }

    /** JSON {"stockLevelId": uuid, "level": ok|low|empty}: sets the place's stock level and records it on the cleaning. */
    #[Route('/api/cleanings/{id}/stock', name: 'api_cleaning_stock', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function stock(#[MapEntity] CleaningTask $task, Request $request): JsonResponse
    {
        $this->assertCanWork($task);
        $this->work->setStock($task, $request->toArray());
        $this->em->flush();

        return $this->json($task->toArray());
    }

    /** Secret link without account (/m/<token>) of a cleaning, generated on first request. Managers only. */
    #[Route('/api/cleanings/{id}/link', name: 'api_cleaning_link', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function link(#[MapEntity] CleaningTask $task): JsonResponse
    {
        $url = $this->notifier->linkUrl($task);
        $this->em->flush();

        return $this->linkView($task, $url);
    }

    /** New secret link: the previous one stops working. */
    #[Route('/api/cleanings/{id}/link', name: 'api_cleaning_link_regenerate', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function regenerateLink(#[MapEntity] CleaningTask $task): JsonResponse
    {
        $task->regenerateLink();
        $url = $this->notifier->linkUrl($task);
        $this->em->flush();

        return $this->linkView($task, $url);
    }

    #[Route('/api/cleanings/{id}/link', name: 'api_cleaning_link_revoke', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function revokeLink(#[MapEntity] CleaningTask $task): JsonResponse
    {
        $task->revokeLink();
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** Users a cleaning can be assigned to (enabled accounts of rocket-core). Administrators only. */
    #[Route('/api/cleaning-assignees', name: 'api_cleaning_assignees', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function assignees(): JsonResponse
    {
        $users = $this->em->getRepository(User::class)->findBy(['enabled' => true], ['email' => 'ASC']);

        return $this->json(array_map(static fn (User $u) => ['id' => $u->getId()->toRfc4122(), 'email' => $u->getEmail(), 'name' => $u->getDisplayName()], $users));
    }

    /** E-mail notifications of cleanings: {"assignment": bool, "late": bool, "summary": bool}. Administrators only. */
    #[Route('/api/cleaning-settings', name: 'api_cleaning_settings', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function settings(): JsonResponse
    {
        return $this->json($this->settings->all());
    }

    #[Route('/api/cleaning-settings', name: 'api_cleaning_settings_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateSettings(Request $request): JsonResponse
    {
        $this->settings->update($request->toArray());
        $this->em->flush();

        return $this->json($this->settings->all());
    }

    #[Route('/api/places/{placeId}/cleaning-checklist', name: 'api_place_cleaning_checklist', methods: ['GET'], requirements: ['placeId' => Requirement::UUID])]
    public function checklist(string $placeId): JsonResponse
    {
        return $this->json($this->checklistView($placeId));
    }

    /** Stock levels of a place ({"id", "name", "level"}, Rocket Place's; empty standalone). */
    #[Route('/api/places/{placeId}/stock', name: 'api_place_stock', methods: ['GET'], requirements: ['placeId' => Requirement::UUID])]
    public function placeStock(string $placeId): JsonResponse
    {
        return $this->json($this->places->stock($placeId));
    }

    /** Content of one of a cleaning's photos ("file:<id>" as listed in "photos"). */
    #[Route('/api/cleanings/{id}/photos/{fileId}', name: 'api_cleaning_photo_content', methods: ['GET'], requirements: ['id' => Requirement::UUID, 'fileId' => 'file:[A-Za-z0-9_-]{1,64}'])]
    public function photoContent(#[MapEntity] CleaningTask $task, string $fileId): Response
    {
        return new Response($this->work->photoContent($task, $fileId), 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store, private']);
    }

    /** JSON {"items": [string, ...]}: replaces the template (existing tasks keep their own copy). */
    #[Route('/api/places/{placeId}/cleaning-checklist', name: 'api_place_cleaning_checklist_update', methods: ['PUT'], requirements: ['placeId' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function updateChecklist(string $placeId, Request $request): JsonResponse
    {
        $labels = array_values(array_filter(array_map(static fn ($l) => mb_substr(trim((string) $l), 0, 160), (array) ($request->toArray()['items'] ?? [])), static fn (string $l) => '' !== $l));
        if (\count($labels) > 100) {
            throw new HttpException(422, '100 points au plus.');
        }
        $this->places->get($placeId);
        foreach ($this->checklistItems->findBy(['placeId' => $placeId]) as $item) {
            $this->em->remove($item);
        }
        $this->em->flush();
        foreach ($labels as $i => $label) {
            $this->em->persist(new CleaningChecklistItem($placeId, $label, $i));
        }
        $this->em->flush();

        return $this->json($this->checklistView($placeId));
    }

    /** @return list<string> */
    private function checklistView(string $placeId): array
    {
        return array_map(static fn (CleaningChecklistItem $i) => $i->getLabel(), $this->checklistItems->findBy(['placeId' => $placeId], ['position' => 'ASC']));
    }

    /** @param array<string, mixed> $body */
    private function applyPlanning(CleaningTask $task, array $body): void
    {
        if (\array_key_exists('dueAt', $body)) {
            $due = null === $body['dueAt'] ? null : ($this->date($body['dueAt']) ?? throw new HttpException(422, 'dueAt invalide.'));
            if (null !== $due && $due < $task->getScheduledAt()) {
                throw new HttpException(422, 'dueAt doit suivre scheduledAt.');
            }
            $task->setDueAt($due);
        }
        if (\array_key_exists('assigneeEmail', $body) || \array_key_exists('assigneeId', $body)) {
            $email = $body['assigneeEmail'] ?? null;
            $id = $body['assigneeId'] ?? null;
            $user = null;
            if (null !== $email && '' !== $email) {
                $user = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim((string) $email))]) ?? throw new HttpException(422, 'Utilisateur inconnu.');
            } elseif (null !== $id && '' !== $id) {
                $user = (Uuid::isValid((string) $id) ? $this->em->find(User::class, Uuid::fromString((string) $id)) : null) ?? throw new HttpException(422, 'Utilisateur inconnu.');
            }
            $task->setAssignee($user);
        }
        if (\array_key_exists('notes', $body) && null === $task->getNotes() && '' !== trim((string) $body['notes'])) {
            $task->setNotes(mb_substr(trim((string) $body['notes']), 0, 5000));
        }
    }

    private function linkView(CleaningTask $task, string $url): JsonResponse
    {
        return $this->json(['url' => $url, 'path' => parse_url($url, \PHP_URL_PATH), 'expiresAt' => CleaningLinkSigner::expiresAt($task)->format(\DATE_ATOM)]);
    }

    /** E-mail to the assignee when the cleaning gets a (new) one; flushes the link salt it may create. */
    private function notifyAssignee(CleaningTask $task, ?User $previous): void
    {
        if (null !== $task->getAssignee() && $task->getAssignee() !== $previous) {
            $actor = $this->getUser();
            $this->notifier->assigned($task, $actor instanceof User ? $actor : null);
            $this->em->flush();
        }
    }

    /** A non-manager may only work on cleanings assigned to them, or not assigned at all. */
    private function assertCanWork(CleaningTask $task): void
    {
        if ($this->isGranted('CLEAN_MANAGE') || null === $task->getAssignee() || $task->getAssignee() === $this->getUser()) {
            return;
        }
        throw new HttpException(403, 'Ce ménage est attribué à quelqu’un d’autre.');
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private function day(string $date): array
    {
        if ('all' === $date) {
            return [null, null];
        }
        $from = '' === $date ? new \DateTimeImmutable('today') : \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $from) {
            throw new HttpException(422, 'date attendue au format AAAA-MM-JJ.');
        }

        return [$from, $from->modify('+1 day')];
    }
}
