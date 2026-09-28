<?php

namespace App\Cleaning;

use Rocket\Core\Settings\Settings;

/**
 * E-mail notifications of cleanings, each can be turned off by an administrator (rocket-core setting
 * "cleaning_notifications"): "assignment" (to the assignee, with the secret link), "late" (daily, to the
 * administrators), "summary" (end-of-day summary, to the administrators), "report" (compte rendu of each completed cleaning, to the administrators). All on by default but "report".
 */
final class CleaningSettings
{
    public const KEYS = ['assignment', 'late', 'summary', 'report'];
    /** Off unless turned on: the compte rendu of each completed cleaning, to the administrators. */
    private const OFF_BY_DEFAULT = ['report'];
    private const NAME = 'cleaning_notifications';

    public function __construct(private readonly Settings $settings)
    {
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        $stored = $this->settings->get(self::NAME, []);
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = \is_array($stored) && \array_key_exists($key, $stored) ? (bool) $stored[$key] : !\in_array($key, self::OFF_BY_DEFAULT, true);
        }

        return $out;
    }

    public function enabled(string $key): bool
    {
        return $this->all()[$key] ?? false;
    }

    /** @param array<string, mixed> $values unknown keys ignored; the caller flushes */
    public function update(array $values): void
    {
        $current = $this->all();
        foreach (self::KEYS as $key) {
            if (\array_key_exists($key, $values)) {
                $current[$key] = (bool) $values[$key];
            }
        }
        $this->settings->set(self::NAME, $current);
    }
}
