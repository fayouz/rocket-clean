<?php

namespace App\Linen;

use App\Linen\Entity\Laundry;
use App\Linen\Entity\LinenBatch;
use App\Linen\Entity\LinenMovement;
use App\Mailer\MailerClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Batches sent to a laundry: departure (dirty → at_laundry, refs batch:<id>:sent:<typeId>), return counted piece
 * by piece (at_laundry → clean, → damaged, missing → lost, refs batch:<id>:returned|damaged|lost:<typeId>),
 * discrepancies, cost (per kg: weight × price; per piece: pieces processed × price of the type), and the drop-off
 * e-mail to the laundry: previewed first, sent through Rocket Mailer only on an explicit confirmation, once.
 */
final class LaundryBatches
{
    public function __construct(
        private readonly LinenCatalog $catalog,
        private readonly LinenLedger $ledger,
        private readonly EntityManagerInterface $em,
        private readonly MailerClient $mailer,
    ) {
    }

    /** @param array<mixed> $body {"lines": [{type|kit, qty}], "weightKg"?: number, "usage"?, "note"?, "sentAt"?} */
    public function send(Laundry $laundry, string $placeId, string $placeName, array $body, ?string $actor): LinenBatch
    {
        $perType = $this->catalog->expand($body, 'lines');
        if ([] === $perType) {
            throw new HttpException(422, 'Un lot demande au moins une ligne.');
        }
        $lines = [];
        foreach ($perType as $typeId => $qty) {
            $lines[] = ['typeId' => $typeId, 'qty' => $qty];
        }
        $batch = new LinenBatch($laundry, $placeId, $placeName, $lines, new \DateTimeImmutable());
        $batch->setUsage(self::usage($body['usage'] ?? 'rental'))->setWeightGrams(self::grams($body['weightKg'] ?? null) ?? $this->estimatedGrams($perType));
        if (isset($body['note']) && '' !== trim((string) $body['note'])) {
            $batch->setNote(mb_substr(trim((string) $body['note']), 0, 1000));
        }
        $this->em->persist($batch);
        foreach ($perType as $typeId => $qty) {
            $this->ledger->move($placeId, $this->catalog->type($typeId), LinenMovement::DIRTY, LinenMovement::AT_LAUNDRY, $qty, 'Envoi à '.$laundry->getName(), 'batch:'.$batch->getId()->toRfc4122().":sent:$typeId", 'linen', $batch->getUsage(), $actor, true);
        }

        return $batch;
    }

    /** @param array<mixed> $body {"returned": [{type, qty}], "damaged"?: [{type, qty}], "weightKg"?: number} */
    public function receive(LinenBatch $batch, array $body, ?string $actor): LinenBatch
    {
        if (LinenBatch::SENT !== $batch->getStatus()) {
            throw new HttpException(409, 'Ce lot est déjà revenu.');
        }
        $returned = $this->catalog->expand($body, 'returned');
        $damaged = $this->catalog->expand($body, 'damaged');
        $sent = array_column($batch->getSentLines(), 'qty', 'typeId');
        foreach (array_keys($returned + $damaged) as $typeId) {
            if (!isset($sent[$typeId])) {
                throw new HttpException(422, \sprintf('« %s » n’était pas dans le lot.', $this->catalog->type($typeId)->getName()));
            }
        }
        if (null !== ($grams = self::grams($body['weightKg'] ?? null))) {
            $batch->setWeightGrams($grams);
        }
        $discrepancies = [];
        $ret = [];
        $dmg = [];
        $id = $batch->getId()->toRfc4122();
        foreach ($sent as $typeId => $qty) {
            $r = $returned[$typeId] ?? 0;
            $d = $damaged[$typeId] ?? 0;
            if ($r + $d > $qty) {
                throw new HttpException(422, \sprintf('« %s » : %d revenus pour %d envoyés.', $this->catalog->type($typeId)->getName(), $r + $d, $qty));
            }
            $missing = $qty - $r - $d;
            $type = $this->catalog->type($typeId);
            foreach ([[LinenMovement::CLEAN, $r, 'returned', 'Retour de '], [LinenMovement::DAMAGED, $d, 'damaged', 'Abîmé au retour de '], [LinenMovement::LOST, $missing, 'lost', 'Manquant au retour de ']] as [$to, $n, $what, $reason]) {
                if ($n > 0) {
                    $this->ledger->move($batch->getPlaceId(), $type, LinenMovement::AT_LAUNDRY, $to, $n, $reason.$batch->getLaundry()->getName(), "batch:$id:$what:$typeId", 'linen', $batch->getUsage(), $actor, true);
                }
            }
            $r > 0 && $ret[] = ['typeId' => $typeId, 'qty' => $r];
            $d > 0 && $dmg[] = ['typeId' => $typeId, 'qty' => $d];
            if ($missing > 0 || $d > 0) {
                $discrepancies[] = ['typeId' => $typeId, 'typeName' => $type->getName(), 'missing' => $missing, 'damaged' => $d];
            }
        }
        $batch->markReturned($ret, $dmg, $discrepancies, new \DateTimeImmutable());
        $batch->setCost($this->cost($batch));

        return $batch;
    }

    /** Cost in cents: per kg (weight known), or per piece processed (sent minus missing). Null when unpriced. */
    public function cost(LinenBatch $batch): ?int
    {
        $laundry = $batch->getLaundry();
        if (Laundry::PER_KG === $laundry->getPricing()) {
            return null === $laundry->getPricePerKg() || null === $batch->getWeightGrams() ? null : (int) round($batch->getWeightGrams() * $laundry->getPricePerKg() / 1000);
        }
        $prices = $laundry->getPiecePrices();
        $lost = [];
        foreach ($batch->toArray()['discrepancies'] as $d) {
            $lost[$d['typeId']] = $d['missing'];
        }
        $total = 0;
        foreach ($batch->getSentLines() as $l) {
            $total += ($l['qty'] - ($lost[$l['typeId']] ?? 0)) * ($prices[$l['typeId']] ?? 0);
        }

        return $total;
    }

    /** @return array{to: list<string>, subject: string, htmlBody: string, alreadySent: bool, sentAt: ?string} */
    public function email(LinenBatch $batch): array
    {
        $laundry = $batch->getLaundry();
        $names = [];
        foreach ($this->catalog->types() as $t) {
            $names[$t->getId()->toRfc4122()] = $t->getName();
        }
        $rows = '';
        foreach ($batch->getSentLines() as $l) {
            $rows .= \sprintf('<tr><td>%s</td><td style="text-align:right">%d</td></tr>', htmlspecialchars($names[$l['typeId']] ?? 'Linge'), $l['qty']);
        }
        $weight = null === $batch->getWeightGrams() ? '' : \sprintf('<p>Poids : %s kg</p>', number_format($batch->getWeightGrams() / 1000, 1, ',', ' '));
        $note = null === $batch->getNote() ? '' : '<p>'.nl2br(htmlspecialchars($batch->getNote())).'</p>';

        return [
            'to' => null === $laundry->getOrderEmail() ? [] : [$laundry->getOrderEmail()],
            'subject' => \sprintf('Dépôt de linge — %s — %s', $batch->getPlaceName(), $batch->getSentAt()->format('d/m/Y')),
            'htmlBody' => \sprintf(
                '<p>Bonjour,</p><p>Voici le linge déposé le %s pour %s :</p><table>%s</table>%s%s<p>Retour souhaité le %s. Merci !</p>',
                $batch->getSentAt()->format('d/m/Y'), htmlspecialchars($batch->getPlaceName()), $rows, $weight, $note, $batch->getExpectedAt()->format('d/m/Y'),
            ),
            'alreadySent' => null !== $batch->getEmailSentAt(),
            'sentAt' => $batch->getEmailSentAt()?->format(\DATE_ATOM),
        ];
    }

    /** Sends the drop-off e-mail once (a second call answers alreadySent without sending). */
    public function sendEmail(LinenBatch $batch, string $asUser): array
    {
        $email = $this->email($batch);
        if ($email['alreadySent']) {
            return $email;
        }
        if ([] === $email['to']) {
            throw new HttpException(422, 'Cette blanchisserie n’a pas d’adresse de commande.');
        }
        $this->mailer->send($asUser, ['to' => $email['to'], 'subject' => $email['subject'], 'htmlBody' => $email['htmlBody']]);
        $batch->markEmailSent();

        return $this->email($batch);
    }

    public static function usage(mixed $value): string
    {
        return \in_array($value, LinenMovement::USAGES, true) ? $value : throw new HttpException(422, 'Usage invalide (rental, personal).');
    }

    private static function grams(mixed $kg): ?int
    {
        if (null === $kg || '' === $kg) {
            return null;
        }
        if (!is_numeric($kg) || (float) $kg < 0 || (float) $kg > 1000) {
            throw new HttpException(422, 'Poids invalide (kg, 0 à 1000).');
        }

        return (int) round((float) $kg * 1000);
    }

    /** @param array<string, int> $perType */
    private function estimatedGrams(array $perType): ?int
    {
        $total = 0;
        foreach ($perType as $typeId => $qty) {
            $w = $this->catalog->type($typeId)->getWeightGrams();
            if (null === $w) {
                return null;
            }
            $total += $w * $qty;
        }

        return $total;
    }
}
