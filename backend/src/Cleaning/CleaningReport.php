<?php

namespace App\Cleaning;

use App\Entity\CleaningTask;

/**
 * Compte rendu of a completed cleaning, stored on the task (CleaningTask::$report) and shown to the managers
 * (GET /api/cleanings/{id}/report, optional e-mail): checklist points done and skipped, stock reports, incidents,
 * photos (by area for the photo round) and duration (startedAt → completedAt).
 */
final class CleaningReport
{
    /** @return array<string, mixed> */
    public static function build(CleaningTask $task, \DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $done = $skipped = [];
        foreach ($task->getChecklist() as $line) {
            if ($line['done']) {
                $done[] = $line['label'];
            } else {
                $skipped[] = $line['label'];
            }
        }
        $started = $task->getStartedAt();
        $completed = $task->getCompletedAt() ?? $now;

        return [
            'generatedAt' => $now->format(\DATE_ATOM),
            'placeName' => $task->getPlaceName(),
            'label' => $task->getLabel(),
            'assignee' => $task->getAssignee()?->getDisplayName(),
            'startedAt' => $started?->format(\DATE_ATOM),
            'completedAt' => $completed->format(\DATE_ATOM),
            'durationMinutes' => null === $started ? null : max(0, intdiv($completed->getTimestamp() - $started->getTimestamp(), 60)),
            'checklist' => ['total' => \count($done) + \count($skipped), 'done' => $done, 'skipped' => $skipped],
            'stock' => array_map(static fn (array $r) => array_intersect_key($r, ['item' => 1, 'level' => 1, 'quantity' => 1, 'at' => 1]), $task->getStockReports()),
            'incidents' => $task->getIncidents(),
            'photos' => $task->getPhotos(),
            'notes' => $task->getNotes(),
        ];
    }

    /** @param array<string, mixed> $report */
    public static function html(array $report): string
    {
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, \ENT_QUOTES);
        $levels = ['ok' => 'OK', 'low' => 'bas', 'empty' => 'vide'];
        $list = static fn (array $items): string => [] === $items ? '<p>—</p>' : '<ul>'.implode('', array_map(static fn (string $i) => '<li>'.$i.'</li>', $items)).'</ul>';
        $checklist = $report['checklist'];
        $out = \sprintf('<p><b>%s</b> — %s%s</p>', $e($report['placeName']), $e($report['label']), null !== $report['assignee'] ? ' ('.$e($report['assignee']).')' : '');
        $out .= \sprintf('<p>Durée : %s · Points faits : %d/%d · Photos : %d</p>', null === $report['durationMinutes'] ? 'inconnue' : $report['durationMinutes'].' min', \count($checklist['done']), $checklist['total'], \count($report['photos']));
        if ([] !== $checklist['skipped']) {
            $out .= '<p><b>Points non faits</b></p>'.$list(array_map($e, $checklist['skipped']));
        }
        if ([] !== $report['incidents']) {
            $out .= '<p><b>Problèmes signalés</b></p>'.$list(array_map(static fn (array $i) => $e($i['text']), $report['incidents']));
        }
        if ([] !== $report['stock']) {
            $out .= '<p><b>Stock</b></p>'.$list(array_map(static fn (array $s) => $e($s['item']).' : '.($levels[$s['level']] ?? $e($s['level'])).(isset($s['quantity']) ? ' ('.$e($s['quantity']).' utilisé(s))' : ''), $report['stock']));
        }
        if (null !== $report['notes']) {
            $out .= '<p><b>Notes</b> : '.$e($report['notes']).'</p>';
        }

        return $out;
    }
}
