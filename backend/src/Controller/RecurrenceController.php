<?php

namespace App\Controller;

use App\Cleaning\CleaningPlanner;
use App\Cleaning\RecurrenceGenerator;
use App\Entity\CleaningRecurrence;
use App\Entity\CleaningTask;
use App\Place\PlaceDirectory;
use App\Repository\CleaningRecurrenceRepository;
use App\Repository\CleaningTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Recurring cleanings of a place (CleaningRecurrence). Reading: CLEAN_READ; changes: CLEAN_MANAGE. Saving a
 * recurrence generates its cleanings for the days ahead at once; deleting it (or turning it off) removes its future
 * cleanings still to do.
 *
 * JSON: {"type"?, "label"?, "frequency": weekly|monthly, "weekdays"?: [1..7], "monthDay"?: 1..31, "nth"?: 1..5|-1,
 * "nthWeekday"?: 1..7, "time"?: "HH:MM", "durationMinutes"?: int, "assigneeId"?|"assigneeEmail"?, "checklist"?: [string],
 * "cost"?: cents|null, "active"?: bool, "startsOn"?: YYYY-MM-DD (default today), "endsOn"?: YYYY-MM-DD|null}.
 */
#[IsGranted('CLEAN_READ')]
final class RecurrenceController extends AbstractController
{
    public function __construct(
        private readonly CleaningRecurrenceRepository $recurrences,
        private readonly CleaningTaskRepository $tasks,
        private readonly RecurrenceGenerator $generator,
        private readonly PlaceDirectory $places,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/places/{placeId}/recurrences', name: 'api_place_recurrences', methods: ['GET'], requirements: ['placeId' => Requirement::UUID])]
    public function list(string $placeId): JsonResponse
    {
        return $this->json(array_map(static fn (CleaningRecurrence $r) => $r->toArray(), $this->recurrences->findBy(['placeId' => $placeId], ['createdAt' => 'ASC'])));
    }

    #[Route('/api/places/{placeId}/recurrences', name: 'api_place_recurrences_create', methods: ['POST'], requirements: ['placeId' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function create(string $placeId, Request $request): JsonResponse
    {
        $site = $this->places->get($placeId);
        $recurrence = new CleaningRecurrence($site->getId(), $site->getName(), new \DateTimeImmutable('today'));
        $this->apply($recurrence, $request->toArray() + ['frequency' => CleaningRecurrence::WEEKLY]);
        $this->em->persist($recurrence);
        $this->em->flush();
        $this->generator->generate($recurrence);

        return $this->json($recurrence->toArray(), 201);
    }

    #[Route('/api/recurrences/{id}', name: 'api_recurrence', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity] CleaningRecurrence $recurrence): JsonResponse
    {
        return $this->json($recurrence->toArray());
    }

    #[Route('/api/recurrences/{id}', name: 'api_recurrence_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function update(#[MapEntity] CleaningRecurrence $recurrence, Request $request): JsonResponse
    {
        $this->apply($recurrence, $request->toArray());
        $this->removeFutureTodo($recurrence);
        $this->em->flush();
        $this->generator->generate($recurrence);

        return $this->json($recurrence->toArray());
    }

    #[Route('/api/recurrences/{id}', name: 'api_recurrence_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('CLEAN_MANAGE')]
    public function delete(#[MapEntity] CleaningRecurrence $recurrence): JsonResponse
    {
        $this->removeFutureTodo($recurrence);
        $this->em->remove($recurrence);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** Future cleanings of the recurrence still to do (not started, not done) are removed; the generator recreates them. */
    private function removeFutureTodo(CleaningRecurrence $recurrence): void
    {
        $prefix = 'recurrence:'.$recurrence->getId()->toRfc4122().':';
        $now = new \DateTimeImmutable();
        foreach ($this->tasks->search(null, null, $recurrence->getPlaceId()) as $task) {
            if (str_starts_with((string) $task->getExternalRef(), $prefix) && CleaningTask::TODO === $task->getStatus() && $task->getScheduledAt() > $now) {
                $this->em->remove($task);
            }
        }
        $this->em->flush();
    }

    /** @param array<string, mixed> $b */
    private function apply(CleaningRecurrence $r, array $b): void
    {
        if (isset($b['type'])) {
            $r->setType(\in_array($b['type'], CleaningTask::TYPES, true) ? $b['type'] : throw new HttpException(422, 'Type invalide.'));
        }
        if (isset($b['label']) && '' !== trim((string) $b['label'])) {
            $r->setLabel(mb_substr(trim((string) $b['label']), 0, 120));
        }
        $frequency = $b['frequency'] ?? null;
        if (CleaningRecurrence::WEEKLY === $frequency || (null === $frequency && isset($b['weekdays']))) {
            $days = array_values(array_unique(array_map('intval', (array) ($b['weekdays'] ?? []))));
            if ([] === $days || array_diff($days, range(1, 7))) {
                throw new HttpException(422, 'weekdays : jours ISO 1 (lundi) à 7 (dimanche), au moins un.');
            }
            sort($days);
            $r->setWeekly($days);
        } elseif (CleaningRecurrence::MONTHLY === $frequency) {
            $monthDay = isset($b['monthDay']) ? (int) $b['monthDay'] : null;
            $nth = isset($b['nth']) ? (int) $b['nth'] : null;
            $nthWeekday = isset($b['nthWeekday']) ? (int) $b['nthWeekday'] : null;
            $validDay = null !== $monthDay && $monthDay >= 1 && $monthDay <= 31;
            $validNth = null !== $nth && \in_array($nth, [-1, 1, 2, 3, 4, 5], true) && null !== $nthWeekday && $nthWeekday >= 1 && $nthWeekday <= 7;
            if ($validDay === $validNth) {
                throw new HttpException(422, 'Mensuel : monthDay (1–31) ou nth (1–5, -1) + nthWeekday (1–7).');
            }
            $r->setMonthly($validDay ? $monthDay : null, $validNth ? $nth : null, $validNth ? $nthWeekday : null);
        } elseif (null !== $frequency) {
            throw new HttpException(422, 'frequency : weekly ou monthly.');
        }
        if (isset($b['time'])) {
            $r->setTime(1 === preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $b['time']) ? (string) $b['time'] : throw new HttpException(422, 'time attendu au format HH:MM.'));
        }
        if (isset($b['durationMinutes'])) {
            $minutes = (int) $b['durationMinutes'];
            $r->setDurationMinutes($minutes >= 15 && $minutes <= 1440 ? $minutes : throw new HttpException(422, 'durationMinutes entre 15 et 1440.'));
        }
        if (\array_key_exists('assigneeEmail', $b) || \array_key_exists('assigneeId', $b)) {
            $user = null;
            if (null !== ($b['assigneeEmail'] ?? null) && '' !== $b['assigneeEmail']) {
                $user = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim((string) $b['assigneeEmail']))]) ?? throw new HttpException(422, 'Utilisateur inconnu.');
            } elseif (null !== ($b['assigneeId'] ?? null) && '' !== $b['assigneeId']) {
                $user = (Uuid::isValid((string) $b['assigneeId']) ? $this->em->find(User::class, Uuid::fromString((string) $b['assigneeId'])) : null) ?? throw new HttpException(422, 'Utilisateur inconnu.');
            }
            $r->setAssignee($user);
        }
        if (\array_key_exists('checklist', $b)) {
            $r->setChecklist(\array_slice(array_values(array_filter(array_map(static fn ($l) => mb_substr(trim((string) $l), 0, 160), (array) $b['checklist']), static fn (string $l) => '' !== $l)), 0, 100));
        }
        if (\array_key_exists('cost', $b)) {
            try {
                $r->setCost(CleaningPlanner::cents($b['cost']));
            } catch (\InvalidArgumentException $e) {
                throw new HttpException(422, $e->getMessage());
            }
        }
        if (\array_key_exists('active', $b)) {
            $r->setActive((bool) $b['active']);
        }
        foreach (['startsOn', 'endsOn'] as $key) {
            if (!\array_key_exists($key, $b)) {
                continue;
            }
            $d = null === $b[$key] || '' === $b[$key] ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $b[$key]) ?: throw new HttpException(422, "$key attendu au format AAAA-MM-JJ."));
            'startsOn' === $key ? $r->setStartsOn($d ?? throw new HttpException(422, 'startsOn requis.')) : $r->setEndsOn($d);
        }
        if (null !== $r->getEndsOn() && $r->getEndsOn() < $r->getStartsOn()) {
            throw new HttpException(422, 'endsOn doit suivre startsOn.');
        }
    }
}
