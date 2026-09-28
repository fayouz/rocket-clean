<?php

namespace App\Linen\Controller;

use App\Linen\Contract\PlaceNames;
use App\Linen\Entity\LinenMovement;
use App\Linen\Entity\LinenWashTask;
use App\Linen\LaundryBatches;
use App\Linen\LinenCatalog;
use App\Linen\LinenLedger;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * In-house washing: planned and assigned by a manager (same people as cleanings: GET /api/cleaning-assignees),
 * carried out by the assignee (or anyone when unassigned). Marking it done moves its lines dirty → clean.
 */
#[IsGranted('LINEN_READ')]
final class LinenWashController extends LinenApiController
{
    public function __construct(
        private readonly LinenCatalog $catalog,
        private readonly LinenLedger $ledger,
        private readonly PlaceNames $places,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** ?date=AAAA-MM-JJ (that day and the open ones before), ?mine=1, ?place=<id>. */
    #[Route('/api/linen/washes', name: 'api_linen_washes', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $qb = $this->em->createQueryBuilder()->select('w')->from(LinenWashTask::class, 'w')->orderBy('w.scheduledAt', 'ASC');
        if ('' !== (string) $request->query->get('date', '')) {
            $day = $this->dayParam($request, 'date');
            $qb->andWhere('(w.scheduledAt >= :from AND w.scheduledAt < :to) OR (w.scheduledAt < :from AND w.status IN (:open))')
                ->setParameter('from', $day)->setParameter('to', $day->modify('+1 day'))->setParameter('open', [LinenWashTask::TODO, LinenWashTask::IN_PROGRESS]);
        } else {
            $qb->setMaxResults(200);
        }
        if ($request->query->getBoolean('mine')) {
            $user = $this->getUser() instanceof User ? $this->getUser() : throw new HttpException(400, '« mine » demande un utilisateur.');
            $qb->andWhere('w.assignee = :me')->setParameter('me', $user);
        }
        if ('' !== ($place = (string) $request->query->get('place', ''))) {
            $qb->andWhere('w.placeId = :place')->setParameter('place', $place);
        }

        return $this->json(array_map(static fn (LinenWashTask $w) => $w->toArray(), $qb->getQuery()->getResult()));
    }

    /** {"placeId", "scheduledAt": ISO-8601, "lines": [{type|kit, qty}], "label"?, "usage"?, "assigneeEmail"?|"assigneeId"?}. */
    #[Route('/api/linen/washes', name: 'api_linen_wash_create', methods: ['POST'])]
    #[IsGranted('LINEN_MANAGE')]
    public function create(Request $request): JsonResponse
    {
        $b = $request->toArray();
        $placeId = $this->placeParam((string) ($b['placeId'] ?? ''));
        $wash = new LinenWashTask($placeId, $this->places->name($placeId), isset($b['label']) ? $this->text($b['label'], 120, 'label') : 'Lessive', $this->date($b['scheduledAt'] ?? null), $this->lines($b));
        $wash->setUsage(LaundryBatches::usage($b['usage'] ?? 'rental'));
        $this->assign($wash, $b);
        $this->em->persist($wash);
        $this->em->flush();

        return $this->json($wash->toArray(), 201);
    }

    /**
     * Assignee (or manager): "steps": {"machine"|"sechage"|"pliage": bool}, "status": todo|in_progress|done|cancelled.
     * Manager: "label", "scheduledAt", "lines", "assigneeEmail"/"assigneeId". Done: its lines go dirty → clean (once).
     */
    #[Route('/api/linen/washes/{id}', name: 'api_linen_wash_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    public function update(#[MapEntity] LinenWashTask $wash, Request $request): JsonResponse
    {
        $b = $request->toArray();
        if (array_intersect(['label', 'scheduledAt', 'lines', 'assigneeEmail', 'assigneeId', 'usage'], array_keys($b))) {
            $this->denyAccessUnlessGranted('LINEN_MANAGE');
            if (LinenWashTask::DONE === $wash->getStatus() && isset($b['lines'])) {
                throw new HttpException(409, 'Lessive terminée : ses lignes ne changent plus.');
            }
            isset($b['label']) && $wash->setLabel($this->text($b['label'], 120, 'label'));
            isset($b['scheduledAt']) && $wash->setScheduledAt($this->date($b['scheduledAt']));
            isset($b['lines']) && $wash->setLines($this->lines($b));
            isset($b['usage']) && $wash->setUsage(LaundryBatches::usage($b['usage']));
            $this->assign($wash, $b);
        } elseif (!$this->isGranted('LINEN_MANAGE') && null !== $wash->getAssignee() && $wash->getAssignee() !== $this->getUser()) {
            throw new HttpException(403, 'Cette lessive est attribuée à quelqu’un d’autre.');
        }
        foreach ((array) ($b['steps'] ?? []) as $step => $done) {
            \in_array($step, LinenWashTask::STEPS, true) ? $wash->setStep($step, true === $done) : throw new HttpException(422, 'Étape inconnue ('.implode(', ', LinenWashTask::STEPS).').');
        }
        if (isset($b['status'])) {
            if (!\in_array($b['status'], LinenWashTask::STATUSES, true)) {
                throw new HttpException(422, 'Statut invalide.');
            }
            if (LinenWashTask::DONE === $wash->getStatus() && LinenWashTask::DONE !== $b['status']) {
                throw new HttpException(409, 'Lessive terminée : le linge est déjà compté propre.');
            }
            $wash->setStatus($b['status']);
            if (LinenWashTask::DONE === $b['status']) {
                foreach (LinenWashTask::STEPS as $step) {
                    $wash->setStep($step, true);
                }
                foreach ($wash->getLines() as $l) {
                    $this->ledger->move($wash->getPlaceId(), $this->catalog->type($l['typeId']), LinenMovement::DIRTY, LinenMovement::CLEAN, $l['qty'], 'Lessive : '.$wash->getLabel(), 'wash:'.$wash->getId()->toRfc4122().':'.$l['typeId'], 'linen', $wash->getUsage(), $this->actor(), true);
                }
            }
        }
        $this->em->flush();

        return $this->json($wash->toArray());
    }

    #[Route('/api/linen/washes/{id}', name: 'api_linen_wash_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('LINEN_MANAGE')]
    public function delete(#[MapEntity] LinenWashTask $wash): JsonResponse
    {
        if (LinenWashTask::DONE === $wash->getStatus()) {
            throw new HttpException(409, 'Lessive terminée : elle reste dans l’historique.');
        }
        $this->em->remove($wash);
        $this->em->flush();

        return $this->json(null, 204);
    }

    /**
     * @param array<mixed> $b
     *
     * @return list<array{typeId: string, qty: int}>
     */
    private function lines(array $b): array
    {
        $out = [];
        foreach ($this->catalog->expand($b, 'lines') as $typeId => $qty) {
            $out[] = ['typeId' => $typeId, 'qty' => $qty];
        }

        return [] !== $out ? $out : throw new HttpException(422, 'Une lessive demande au moins une ligne.');
    }

    /** @param array<mixed> $b */
    private function assign(LinenWashTask $wash, array $b): void
    {
        if (!\array_key_exists('assigneeEmail', $b) && !\array_key_exists('assigneeId', $b)) {
            return;
        }
        $email = $b['assigneeEmail'] ?? null;
        $id = $b['assigneeId'] ?? null;
        $user = null;
        if (null !== $email && '' !== $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim((string) $email))]) ?? throw new HttpException(422, 'Utilisateur inconnu.');
        } elseif (null !== $id && '' !== $id) {
            $user = (Uuid::isValid((string) $id) ? $this->em->find(User::class, Uuid::fromString((string) $id)) : null) ?? throw new HttpException(422, 'Utilisateur inconnu.');
        }
        $wash->setAssignee($user);
    }

    private function date(mixed $value): \DateTimeImmutable
    {
        if (\is_string($value) && '' !== $value) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
            }
        }
        throw new HttpException(422, '« scheduledAt » : date ISO-8601 attendue.');
    }
}
