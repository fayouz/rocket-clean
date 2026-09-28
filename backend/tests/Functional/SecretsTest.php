<?php

namespace App\Tests\Functional;

use App\Secrets\IntegrationSecrets;
use App\Tests\ApiTestTrait;
use Rocket\Core\Repository\SecretRepository;
use Rocket\Core\Secrets\SecretsKeyring;
use Rocket\Core\Secrets\SecretVault;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\ErrorHandler\BufferingLogger;

/** Integration secrets: read from the rocket-core vault, legacy environment fallback, never exposed by the API. */
final class SecretsTest extends WebTestCase
{
    use ApiTestTrait;

    private const NAME = 'rocket.stock.token';
    private const ENV = 'ROCKET_STOCK_TOKEN';

    protected function tearDown(): void
    {
        unset($_SERVER[self::ENV], $_ENV[self::ENV]);
        putenv(self::ENV);
        parent::tearDown();
    }

    private function vault(BufferingLogger $logger): SecretVault
    {
        return new SecretVault(static::getContainer()->get(SecretRepository::class), $this->em(), static::getContainer()->get(SecretsKeyring::class), new NativeClock(), $logger);
    }

    public function testEverySecretHasItsLegacyVariable(): void
    {
        self::assertArrayHasKey(self::NAME, IntegrationSecrets::LEGACY_ENV);
        self::assertSame(self::ENV, IntegrationSecrets::LEGACY_ENV[self::NAME]);
        foreach (array_keys(IntegrationSecrets::LEGACY_ENV) as $name) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+(\.[a-z0-9_]+)+$/', $name);
        }
    }

    public function testSecretIsReadFromTheVault(): void
    {
        $_SERVER[self::ENV] = 'from-the-environment';
        $logger = new BufferingLogger();
        $vault = $this->vault($logger);
        $vault->set(self::NAME, 'from-the-vault-123456');

        self::assertSame('from-the-vault-123456', (new IntegrationSecrets($vault))->get(self::NAME));
        self::assertSame([], array_filter($logger->cleanLogs(), static fn (array $log) => 'warning' === $log[0]));
        self::assertSame('from-the-vault-123456', static::getContainer()->get(IntegrationSecrets::class)->get(self::NAME));
    }

    public function testLegacyEnvironmentVariableIsAFallbackLoggedAsDeprecated(): void
    {
        $logger = new BufferingLogger();
        $secrets = new IntegrationSecrets($this->vault($logger));
        self::assertSame('', $secrets->get(self::NAME));

        $_SERVER[self::ENV] = 'legacy-value';
        self::assertSame('legacy-value', $secrets->get(self::NAME));
        $warnings = array_values(array_filter($logger->cleanLogs(), static fn (array $log) => 'warning' === $log[0]));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('deprecated', $warnings[0][1]);
        self::assertSame(self::ENV, $warnings[0][2]['env']);
    }

    public function testMigrateEnvCommandImportsTheLegacyVariablesOnce(): void
    {
        $_SERVER[self::ENV] = 'legacy-value-abcdef';
        $tester = new CommandTester((new ConsoleApplication(static::$kernel ?? self::bootKernel()))->find('app:secrets:migrate-env'));

        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        $vault = static::getContainer()->get(SecretVault::class);
        self::assertFalse($vault->has(self::NAME));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame('legacy-value-abcdef', $vault->get(self::NAME));

        // Idempotent: an existing secret is kept, unless --overwrite.
        $_SERVER[self::ENV] = 'another-value-123456';
        $tester->execute([]);
        self::assertStringContainsString('déjà dans le coffre', $tester->getDisplay());
        self::assertSame('legacy-value-abcdef', $this->vault(new BufferingLogger())->get(self::NAME));
        $tester->execute(['--overwrite' => true]);
        self::assertSame('another-value-123456', $this->vault(new BufferingLogger())->get(self::NAME));
    }

    public function testTheApiNeverExposesTheValues(): void
    {
        static::getContainer()->get(SecretVault::class)->set(self::NAME, 'very-secret-value-9876');
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin-secrets@example.org', ['ROLE_ADMIN']));

        $this->api('POST', '/api/secrets', ['name' => 'other.token', 'value' => 'another-secret-value-5555'], $admin);
        $this->assertStatus(201);
        self::assertStringNotContainsString('another-secret-value', (string) $this->client->getResponse()->getContent());

        $list = $this->api('GET', '/api/secrets', null, $admin);
        $this->assertStatus(200);
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('very-secret-value', $body);
        self::assertStringNotContainsString('another-secret-value', $body);
        self::assertContains(self::NAME, array_column($list['secrets'], 'name'));
        self::assertContains('••••9876', array_column($list['secrets'], 'masked'));

        $this->api('GET', '/api/secrets', null, 'Bearer '.$this->jwtFor($this->createUser('user-secrets@example.org')));
        $this->assertStatus(403);
    }
}
