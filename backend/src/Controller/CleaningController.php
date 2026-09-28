<?php

namespace App\Controller;

use App\Cleaning\CleaningLinkSigner;
use App\Cleaning\CleaningNotifier;
use App\Cleaning\CleaningPlanner;
use App\Cleaning\CleaningSettings;
use App\Cleaning\CleaningWork;
use App\Entity\CleaningChecklistItem;
use App\Entity\CleaningTask;
use App\Entity\OccupiedPeriod;
use App\Repository\CleaningChecklistItemRepository;
use App\Repository\CleaningTaskRepository;
use App\Place\PlaceDirectory;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Rocket\Core\Security\ApplicationUser;
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
        private readonly CleaningPlanner $planner,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Query: date=YYYY-MM-DD (default today, "all" for no date filter), mine=1, place=<place id>, type=rental|personal|maintenance. Late open tasks are included with a date. */
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

        return $this->json(array_map(static fn (CleaningTask $t) => $t->toArray($now), $this->tasks->search($from, $to, '' === $placeId ? null : $placeId, $assignee, null !== $from, $this->typeFilter($request))));
    }

    /** Cleanings of a place (known locally; the place itself is not looked up, so a PMS can read them cheaply). */
    #[Route('/api/places/{placeId}/cleanings', name: 'api_place_cleanings', methods: ['GET'], requirements: ['placeId' => Requirement::UUID])]
    public function listForPlace(string $placeId, Request $request): JsonResponse
    {
        $now = new \DateTimeImmutable();

        return $this->json(array_map(static fn (CleaningTask $t) => $t->toArray($now), $this->tasks->search(null, null, $placeId, null, false, $this->typeFilter($request))));
    }

    /**
     * Cost export: cleanings (not cancelled) with their cost and a total, e.g. the rental ones for the host's bilan.
     * Query: type, place, from/to (YYYY-MM-DD, on scheduledAt, "to" inclusive).
     */
    #[Route('/api/cleanings/export', name: 'api_cleanings_export', methods: ['GET'])]
    #[IsGranted('CLEAN_MANAGE')]
    public function export(Request $request): JsonResponse
    {
        $from = $this->dayParam($request, 'from');
        $to = $this->dayParam($request, 'to')?->modify('+1 day');
        $placeId = (string) $request->query->get('place', '');
        $rows = [];
        $total = 0;
        foreach ($this->tasks->search(null, null, '' === $placeId ? null : $placeId, null, false, $this->typeFilter($request)) as $t) {
            if (CleaningTask::CANCELLED === $t->getStatus() || (null !== $from && $t->getScheduledAt() < $from) || (null !== $to && $t->getScheduledAt() >= $to)) {
                continue;
            }
            $total += $t->getCost() ?? 0;
            $rows[] = ['id' => $t->getId()->toRfc4122(), 'placeId' => $t->getPlaceId(), 'placeName' => $t->getPlaceName(), 'type' => $t->getType(), 'label' => $t->getLabel(),
                'scheduledAt' => $t->getScheduledAt()->format(\DATE_ATOM), 'status' => $t->getStatus(), 'cost' => $t->getCost(), 'externalRef' => $t->getExternalRef()];
        }

        return $this->json(['currency' => 'EUR', 'unit' => 'cents', 'total' => $total, 'count' => \count($rows), 'items' => $rows]);
    }

    /**
     * JSON {"scheduledAt": ISO-8601, "dueAt"?: ISO-8601|null, "label"?: string, "assigneeEmail"?|"assigneeId"?: string,
     * "notes"?: string, "externalRef"?: string, "type"?: rental|personal|maintenance (default: rental for an externalRef
     * "booking:…", else personal), "cost"?: cents, "origin"?: host|pms|place (applications only; default pms)}.
     * Find-or-create by externalRef: 200 with the existing task (unchanged).
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
        $scheduledAt = $this->date($body['scheduledAt'] ?? null) ?? throw new HttpException(422, 'scheduledAt requis.');
        $type = isset($body['type']) ? $this->type($body['type']) : (null !== $externalRef && str_starts_with($externalRef, 'booking:') ? CleaningTask::RENTAL : CleaningTask::PERSONAL);
        $task = (new CleaningTask($site->getId(), $site->getName(), mb_substr($label, 0, 120), $scheduledAt, $this->planner->checklistFor($placeId, $type), $externalRef))->setType($type);
        $user = $this->getUser();
        if ($user instanceof ApplicationUser) {
            $origin = \in_array($body['origin'] ?? null, ['host', 'pms', 'place'], true) ? $body['origin'] : 'pms';
            $task->setOrigin($origin, mb_substr($user->getApplication()->getName(), 0, 120));
        }
        $task->setCost(\array_key_exists('cost', $body) ? $this->cost($body['cost']) : $this->planner->defaultCost($placeId, $task->getType()));
        $this->applyPlanning($task, $body);
        $this->planner->refreshConflict($task);
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
        $this->assertAppOwns($task);
        $body = $request->toArray();
        $previousAssignee = $task->getAssignee();
        if (array_intersect(['label', 'scheduledAt', 'dueAt', 'assigneeEmail', 'assigneeId', 'type', 'cost'], array_keys($body))) {
            $this->denyAccessUnlessGranted('CLEAN_MANAGE');
            if (isset($body['type'])) {
                $task->setType($this->type($body['type']));
            }
            if (\array_key_exists('cost', $body)) {
                $task->setCost($this->cost($body['cost']));
            }
            if (isset($body['label']) && '' !== trim((string) $body['label'])) {
                $task->setLabel(mb_substr(trim((string) $body['label']), 0, 120));
            }
            if (isset($body['scheduledAt'])) {
                $task->setScheduledAt($this->date($body['scheduledAt']) ?? throw new HttpException(422, 'scheduledAt invalide.'));
            }
            $this->applyPlanning($task, $body);
        }
        $this->work->apply($task, $body);
        $this->planner->refreshConflict($task);
        $this->em->flush();
        $this->notifyAssignee($task, $previousAssignee);

        return $this->json($task->toArray());
    }

    #[Route('/api/cleanings/{id}', name: 'api_cleaning_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function delete(#[MapEntity] CleaningTask $task): JsonResponse
    {
        $this->assertAppOwns($task);
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
    public function checklist(string $placeId, Request $request): JsonResponse
    {
        return $this->json($this->planner->checklist($placeId, $this->templateType($request)));
    }

    /** Occupied periods of a place [{"from", "until", "externalRef"}]. */
    #[Route('/api/places/{placeId}/occupancy', name: 'api_place_occupancy', methods: ['GET'], requirements: ['placeId' => Requirement::UUID])]
    public function occupancy(string $placeId): JsonResponse
    {
        return $this->json(array_map(static fn (OccupiedPeriod $p) => $p->toArray(), $this->em->getRepository(OccupiedPeriod::class)->findBy(['placeId' => $placeId], ['from' => 'ASC'])));
    }

    /**
     * JSON [{"from": ISO-8601, "until": ISO-8601, "externalRef"?: string}, ...] (or {"periods": [...]}): replaces the
     * occupied periods of the place (pushed by Rocket Host or a PMS), then flags the open personal/maintenance
     * cleanings of the place that overlap one ("conflict").
     */
    #[Route('/api/places/{placeId}/occupancy', name: 'api_place_occupancy_update', methods: ['PUT'], requirements: ['placeId' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function updateOccupancy(string $placeId, Request $request): JsonResponse
    {
        $body = $request->toArray();
        $list = array_is_list($body) ? $body : (array) ($body['periods'] ?? []);
        if (\count($list) > 1000) {
            throw new HttpException(422, '1000 périodes au plus.');
        }
        $periods = [];
        foreach ($list as $p) {
            $from = $this->date(\is_array($p) ? ($p['from'] ?? null) : null);
            $until = $this->date(\is_array($p) ? ($p['until'] ?? null) : null);
            if (null === $from || null === $until || $until <= $from) {
                throw new HttpException(422, 'Chaque période demande from < until (ISO-8601).');
            }
            $ref = null === ($p['externalRef'] ?? null) ? null : mb_substr((string) $p['externalRef'], 0, 120);
            $periods[] = new OccupiedPeriod($placeId, $from, $until, $ref);
        }
        foreach ($this->em->getRepository(OccupiedPeriod::class)->findBy(['placeId' => $placeId]) as $old) {
            $this->em->remove($old);
        }
        array_walk($periods, fn (OccupiedPeriod $p) => $this->em->persist($p));
        $this->em->flush();
        foreach ($this->tasks->search(null, null, $placeId) as $task) {
            $this->planner->refreshConflict($task);
        }
        $this->em->flush();

        return $this->json(array_map(static fn (OccupiedPeriod $p) => $p->toArray(), $periods));
    }

    /** Default cost (cents) per type of cleaning at a place {"rental": int|null, "personal": …, "maintenance": …}. */
    #[Route('/api/places/{placeId}/cleaning-costs', name: 'api_place_cleaning_costs', methods: ['GET'], requirements: ['placeId' => Requirement::UUID])]
    public function costs(string $placeId): JsonResponse
    {
        return $this->json($this->planner->costs($placeId));
    }

    #[Route('/api/places/{placeId}/cleaning-costs', name: 'api_place_cleaning_costs_update', methods: ['PUT'], requirements: ['placeId' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function updateCosts(string $placeId, Request $request): JsonResponse
    {
        try {
            $this->planner->setCosts($placeId, $request->toArray());
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
        $this->em->flush();

        return $this->json($this->planner->costs($placeId));
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

    /** JSON {"items": [string, ...]}, query type (default rental): replaces the template of that type (existing tasks keep their own copy). */
    #[Route('/api/places/{placeId}/cleaning-checklist', name: 'api_place_cleaning_checklist_update', methods: ['PUT'], requirements: ['placeId' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function updateChecklist(string $placeId, Request $request): JsonResponse
    {
        $labels = array_values(array_filter(array_map(static fn ($l) => mb_substr(trim((string) $l), 0, 160), (array) ($request->toArray()['items'] ?? [])), static fn (string $l) => '' !== $l));
        if (\count($labels) > 100) {
            throw new HttpException(422, '100 points au plus.');
        }
        $type = $this->templateType($request);
        $this->places->get($placeId);
        foreach ($this->checklistItems->findBy(['placeId' => $placeId, 'type' => $type]) as $item) {
            $this->em->remove($item);
        }
        $this->em->flush();
        foreach ($labels as $i => $label) {
            $this->em->persist(new CleaningChecklistItem($placeId, $label, $i, $type));
        }
        $this->em->flush();

        return $this->json($this->planner->checklist($placeId, $type));
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

    /** An application may change or delete the cleanings it created, and those created by people, not another app's. */
    private function assertAppOwns(CleaningTask $task): void
    {
        $user = $this->getUser();
        if ($user instanceof ApplicationUser && null !== $task->getOriginApp() && $task->getOriginApp() !== mb_substr($user->getApplication()->getName(), 0, 120)) {
            throw new HttpException(403, 'Ce ménage a été créé par une autre application.');
        }
    }

    private function type(mixed $value): string
    {
        return \in_array($value, CleaningTask::TYPES, true) ? $value : throw new HttpException(422, 'Type invalide ('.implode(', ', CleaningTask::TYPES).').');
    }

    private function typeFilter(Request $request): ?string
    {
        $type = (string) $request->query->get('type', '');

        return '' === $type ? null : $this->type($type);
    }

    private function templateType(Request $request): string
    {
        return $this->typeFilter($request) ?? CleaningTask::RENTAL;
    }

    private function cost(mixed $value): ?int
    {
        try {
            return CleaningPlanner::cents($value);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    private function dayParam(Request $request, string $name): ?\DateTimeImmutable
    {
        $value = (string) $request->query->get($name, '');
        if ('' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: throw new HttpException(422, "$name attendu au format AAAA-MM-JJ.");
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
