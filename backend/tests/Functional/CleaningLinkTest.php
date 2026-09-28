<?php

namespace App\Tests\Functional;

use App\Cleaning\CleaningNotifier;
use App\Mailer\DemoMailer;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Secret link of a cleaning (/m/<token>), assignee picker, e-mail notifications (DemoMailer: no network). */
final class CleaningLinkTest extends WebTestCase
{
    use ApiTestTrait;

    private string $admin;
    private string $alice;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(DemoMailer::class)->reset();
        static::getContainer()->get('cache.app')->clear();
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
    }

    /** @return array{0: string, 1: string} place id, cleaning id */
    private function cleaning(array $extra = []): array
    {
        $place = $this->api('POST', '/api/places', ['name' => 'Le port'], $this->admin)['id'];
        $this->api('PUT', "/api/places/$place/cleaning-checklist", ['items' => ['Draps', 'Poubelles']], $this->admin);
        $id = $this->api('POST', "/api/places/$place/cleanings", ['scheduledAt' => (new \DateTimeImmutable('today 10:00'))->format(\DATE_ATOM)] + $extra, $this->admin)['id'];

        return [$place, $id];
    }

    public function testSecretLinkRunsOnlyThatCleaning(): void
    {
        [$place, $id] = $this->cleaning();
        [, $other] = $this->cleaning();

        $this->api('GET', "/api/cleanings/$id/link", null, $this->alice);
        $this->assertStatus(403);
        $link = $this->api('GET', "/api/cleanings/$id/link", null, $this->admin);
        $this->assertStatus(200);
        self::assertMatchesRegularExpression('#^http://localhost:4000/m/[A-Za-z0-9_-]{43}$#', $link['url']);
        $token = substr($link['path'], 3);
        self::assertSame($link['url'], $this->api('GET', "/api/cleanings/$id/link", null, $this->admin)['url']);

        $view = $this->api('GET', "/api/public/cleaning/$token");
        $this->assertStatus(200);
        self::assertSame($id, $view['id']);
        self::assertSame([], $view['stock']); // standalone: no stock (Rocket Place owns it)
        self::assertArrayNotHasKey('externalRef', $view);
        $headers = $this->client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'));

        $view = $this->api('PATCH', "/api/public/cleaning/$token", ['status' => 'in_progress', 'checklist' => [['index' => 1, 'done' => true]], 'label' => 'Piraté', 'assigneeEmail' => 'alice@example.org']);
        $this->assertStatus(200);
        self::assertSame('in_progress', $view['status']);
        self::assertTrue($view['checklist'][1]['done']);
        self::assertSame('Ménage', $view['label']);
        self::assertNull($view['assignee']);

        $this->api('POST', "/api/public/cleaning/$token/stock", ['stockLevelId' => '0192f7c4-0000-7000-8000-000000000009', 'level' => 'empty']);
        $this->assertStatus(404);
        $png = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->client->request('POST', "/api/public/cleaning/$token/photos", ['moment' => 'damage'], ['file' => new UploadedFile($png, 'x.png', 'image/png', null, true)], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatus(201);
        $fileId = json_decode((string) $this->client->getResponse()->getContent(), true)['photos'][0]['fileId'];
        $this->client->request('GET', "/api/public/cleaning/$token/photos/$fileId");
        $this->assertStatus(200);
        $this->client->request('GET', "/api/public/cleaning/$token/photos/file:demo-file-$place");
        $this->assertStatus(404);

        // Another cleaning's link does not open this one; a forged token fails.
        $otherToken = substr($this->api('GET', "/api/cleanings/$other/link", null, $this->admin)['path'], 3);
        self::assertSame($other, $this->api('GET', "/api/public/cleaning/$otherToken")['id']);
        // (flip a middle character: the last one also carries base64 padding bits, which may decode the same)
        $this->api('GET', '/api/public/cleaning/'.substr($token, 0, 30).('A' === $token[30] ? 'B' : 'A').substr($token, 31));
        $this->assertStatus(404);

        // Regenerate: the old link stops working. Revoke: no link at all.
        $new = substr($this->api('POST', "/api/cleanings/$id/link", null, $this->admin)['path'], 3);
        self::assertNotSame($token, $new);
        $this->api('GET', "/api/public/cleaning/$token");
        $this->assertStatus(404);
        $this->api('GET', "/api/public/cleaning/$new");
        $this->assertStatus(200);
        $this->api('DELETE', "/api/cleanings/$id/link", null, $this->admin);
        $this->api('GET', "/api/public/cleaning/$new");
        $this->assertStatus(404);
    }

    public function testLinkExpiresAndIsRateLimited(): void
    {
        [, $id] = $this->cleaning();
        $this->api('PATCH', "/api/cleanings/$id", ['scheduledAt' => (new \DateTimeImmutable('-3 days 10:00'))->format(\DATE_ATOM)], $this->admin);
        $token = substr($this->api('GET', "/api/cleanings/$id/link", null, $this->admin)['path'], 3);
        $this->api('GET', "/api/public/cleaning/$token");
        $this->assertStatus(404);

        for ($i = 0; $i < 10; ++$i) {
            $this->api('GET', '/api/public/cleaning/'.str_repeat('A', 43));
        }
        $this->api('GET', '/api/public/cleaning/'.str_repeat('A', 43));
        $this->assertStatus(429);
    }

    public function testAssigneesListIsForAdmins(): void
    {
        $this->api('GET', '/api/cleaning-assignees', null, $this->alice);
        $this->assertStatus(403);
        $list = $this->api('GET', '/api/cleaning-assignees', null, $this->admin);
        self::assertSame(['admin@example.org', 'alice@example.org'], array_column($list, 'email'));
    }

    public function testAssignmentEmailCarriesTheLinkAndCanBeDisabled(): void
    {
        $mailer = static::getContainer()->get(DemoMailer::class);
        [, $id] = $this->cleaning(['assigneeEmail' => 'alice@example.org']);
        $sent = $mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame(['alice@example.org'], $sent[0]['to']);
        self::assertSame('admin@example.org', $sent[0]['as']);
        self::assertStringContainsString('http://localhost:4000/m/', $sent[0]['htmlBody']);

        // Same assignee again: no new e-mail.
        $this->api('PATCH', "/api/cleanings/$id", ['assigneeEmail' => 'alice@example.org'], $this->admin);
        self::assertCount(1, $mailer->sent());

        $this->api('PUT', '/api/cleaning-settings', ['assignment' => false], $this->alice);
        $this->assertStatus(403);
        self::assertSame(['assignment' => false, 'late' => true, 'summary' => true, 'report' => false], $this->api('PUT', '/api/cleaning-settings', ['assignment' => false, 'bogus' => 1], $this->admin));
        $this->cleaning(['assigneeEmail' => 'alice@example.org']);
        self::assertCount(1, $mailer->sent());
    }

    public function testDailyLateAndSummaryEmailsGoToAdmins(): void
    {
        $mailer = static::getContainer()->get(DemoMailer::class);
        $notifier = static::getContainer()->get(CleaningNotifier::class);
        self::assertFalse($notifier->late());
        [, $id] = $this->cleaning();
        $this->api('PATCH', "/api/cleanings/$id", ['scheduledAt' => (new \DateTimeImmutable('-2 days 10:00'))->format(\DATE_ATOM)], $this->admin);
        [, $today] = $this->cleaning();
        $this->api('PATCH', "/api/cleanings/$today", ['status' => 'done', 'notes' => 'Tache canapé'], $this->admin);

        self::assertTrue($notifier->late());
        $late = $mailer->sent()[0];
        self::assertSame(['admin@example.org'], $late['to']);
        self::assertStringContainsString('Ménages en retard : 1', $late['subject']);

        self::assertTrue($notifier->summary());
        $summary = $mailer->sent()[1];
        self::assertStringContainsString('1/1 terminés', $summary['subject']);
        self::assertStringContainsString('Tache canapé', $summary['htmlBody']);

        $this->api('PUT', '/api/cleaning-settings', ['late' => false, 'summary' => false], $this->admin);
        self::assertFalse($notifier->late());
        self::assertFalse($notifier->summary());
    }
}
