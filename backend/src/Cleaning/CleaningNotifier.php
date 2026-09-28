<?php

namespace App\Cleaning;

use App\Entity\CleaningTask;
use App\Mailer\MailerClient;
use App\Repository\CleaningTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Rocket\Core\Entity\User;
use Rocket\Core\Suite\SuiteSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * E-mails of cleanings, sent through Rocket Mailer (MailerClient): the assignment (to the assignee, with the secret
 * link /m/<token>), the late cleanings and the end-of-day summary (to the enabled administrators). Each kind can be
 * turned off (CleaningSettings). A failed send never fails the caller: it is logged and reported as false.
 * Rocket Mailer sends on behalf of a user it knows: the acting administrator, else ROCKET_MAILER_SENDER, else the
 * first administrator.
 */
final class CleaningNotifier
{
    public function __construct(
        private readonly MailerClient $mailer,
        private readonly CleaningSettings $settings,
        private readonly CleaningLinkSigner $signer,
        private readonly CleaningTaskRepository $tasks,
        private readonly SuiteSettings $suite,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(ROCKET_MAILER_SENDER)%')] private readonly string $sender = '',
    ) {
    }

    /** Public address of the secret link of a cleaning (generated if it has none yet; the caller flushes). */
    public function linkUrl(CleaningTask $task): string
    {
        if (null === $task->getLinkSalt()) {
            $task->regenerateLink();
        }

        return $this->suite->publicUrl().'/m/'.$this->signer->sign($task);
    }

    public function assigned(CleaningTask $task, ?User $actor): bool
    {
        $assignee = $task->getAssignee();
        if (null === $assignee || !$this->settings->enabled('assignment') || \in_array($task->getStatus(), [CleaningTask::DONE, CleaningTask::CANCELLED], true)) {
            return false;
        }
        $url = $this->linkUrl($task);
        $html = \sprintf(
            '<p>Bonjour %s,</p><p>Un ménage t’est attribué : <b>%s</b> — %s, le %s%s.</p><p><a href="%s">Ouvrir la checklist</a> (lien personnel, sans compte, valable jusqu’au %s).</p>',
            self::e($assignee->getFirstName() ?? $assignee->getDisplayName()), self::e($task->getPlaceName()), self::e($task->getLabel()),
            $task->getScheduledAt()->format('d/m/Y H:i'), null !== $task->getDueAt() ? ' → '.$task->getDueAt()->format('H:i') : '',
            self::e($url), CleaningLinkSigner::expiresAt($task)->format('d/m/Y'),
        );

        return $this->send($actor, [$assignee->getEmail()], 'Ménage : '.$task->getPlaceName().' le '.$task->getScheduledAt()->format('d/m'), $html);
    }

    /** Daily: the open cleanings past their deadline, to the administrators. Nothing sent when none. */
    public function late(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        $late = $this->tasks->late($now);
        if ([] === $late || !$this->settings->enabled('late')) {
            return false;
        }
        $rows = implode('', array_map(static fn (CleaningTask $t) => \sprintf('<li><b>%s</b> — %s, prévu le %s (%s)</li>', self::e($t->getPlaceName()), self::e($t->getLabel()), $t->getScheduledAt()->format('d/m H:i'), self::e($t->getAssignee()?->getDisplayName() ?? 'non attribué')), $late));

        return $this->send(null, $this->adminEmails(), \sprintf('Ménages en retard : %d', \count($late)), "<p>Ménages non terminés après leur échéance :</p><ul>$rows</ul>");
    }

    /** End of day: the day's cleanings by status, damage photos and notes, to the administrators. Nothing sent when none. */
    public function summary(\DateTimeImmutable $day = new \DateTimeImmutable()): bool
    {
        $from = $day->setTime(0, 0);
        $tasks = $this->tasks->search($from, $from->modify('+1 day'));
        if ([] === $tasks || !$this->settings->enabled('summary')) {
            return false;
        }
        $labels = [CleaningTask::TODO => 'à faire', CleaningTask::IN_PROGRESS => 'en cours', CleaningTask::DONE => 'terminé', CleaningTask::CANCELLED => 'annulé'];
        $done = \count(array_filter($tasks, static fn (CleaningTask $t) => CleaningTask::DONE === $t->getStatus()));
        $rows = implode('', array_map(static function (CleaningTask $t) use ($labels): string {
            $damage = \count(array_filter($t->getPhotos(), static fn (array $p) => 'damage' === $p['moment']));
            $checked = \count(array_filter($t->getChecklist(), static fn (array $c) => $c['done']));

            return \sprintf('<li><b>%s</b> — %s : %s%s%s%s</li>', self::e($t->getPlaceName()), self::e($t->getLabel()), $labels[$t->getStatus()] ?? $t->getStatus(),
                [] === $t->getChecklist() ? '' : \sprintf(', %d/%d points', $checked, \count($t->getChecklist())),
                $damage > 0 ? \sprintf(', <b>%d photo(s) de dégât</b>', $damage) : '',
                null !== $t->getNotes() ? ' — « '.self::e(mb_substr($t->getNotes(), 0, 200)).' »' : '');
        }, $tasks));

        return $this->send(null, $this->adminEmails(), \sprintf('Ménages du %s : %d/%d terminés', $from->format('d/m'), $done, \count($tasks)), "<ul>$rows</ul>");
    }

    /** Compte rendu of a completed cleaning (CleaningReport), to the administrators, when the "report" notification is on. */
    public function report(CleaningTask $task): bool
    {
        $report = $task->getReport();
        if (null === $report || !$this->settings->enabled('report')) {
            return false;
        }

        return $this->send(null, $this->adminEmails(), 'Compte rendu de ménage : '.$task->getPlaceName(), CleaningReport::html($report));
    }

    /** @param list<string> $to */
    private function send(?User $actor, array $to, string $subject, string $html): bool
    {
        $as = $actor?->getEmail() ?? ('' !== trim($this->sender) ? trim($this->sender) : ($this->adminEmails()[0] ?? null));
        if ([] === $to || null === $as) {
            return false;
        }
        try {
            $this->mailer->send($as, ['to' => $to, 'subject' => $subject, 'htmlBody' => $html]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Cleaning e-mail not sent: {message}', ['message' => $e->getMessage(), 'subject' => $subject]);

            return false;
        }
    }

    /** @return list<string> */
    private function adminEmails(): array
    {
        $users = $this->em->getRepository(User::class)->findBy(['enabled' => true], ['email' => 'ASC']);

        return array_values(array_map(static fn (User $u) => $u->getEmail(), array_filter($users, static fn (User $u) => \in_array('ROLE_ADMIN', $u->getRoles(), true))));
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES);
    }
}
