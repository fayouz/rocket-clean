<?php

namespace App\Secrets;

use Rocket\Core\Secrets\SecretVault;

/**
 * Integration secrets of the brick (tokens of the other Rocket bricks...), read from the rocket-core secrets vault
 * (Administration → Secrets). Transition: a secret missing from the vault falls back to its legacy environment
 * variable (SecretVault::getOrEnv() logs a deprecation), import them with `php bin/console app:secrets:migrate-env`.
 * Values are read at call time (never compiled into the container) and never exposed by the API.
 */
final class IntegrationSecrets
{
    /** Secret name => legacy environment variable. */
    public const LEGACY_ENV = [
        'rocket.place.token' => 'ROCKET_PLACE_TOKEN',
        'rocket.stock.token' => 'ROCKET_STOCK_TOKEN',
        'rocket.cloud.token' => 'ROCKET_CLOUD_TOKEN',
        'rocket.mailer.token' => 'ROCKET_MAILER_TOKEN',
    ];

    /** @param array<string, string> $fixed values that bypass the vault (unit tests) */
    public function __construct(private readonly ?SecretVault $vault = null, private readonly array $fixed = [])
    {
    }

    /** @param array<string, string> $values */
    public static function fixed(array $values): self
    {
        return new self(null, $values);
    }

    /** The value, '' when neither the vault nor the legacy environment variable has it. */
    public function get(string $name): string
    {
        if (\array_key_exists($name, $this->fixed)) {
            return $this->fixed[$name];
        }
        $env = self::LEGACY_ENV[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown integration secret "%s".', $name));

        return $this->vault?->getOrEnv($name, $env) ?? '';
    }
}
