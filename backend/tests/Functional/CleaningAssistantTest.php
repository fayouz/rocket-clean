<?php

namespace App\Tests\Functional;

use App\Mailer\DemoMailer;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** What the voice assistant relies on: checklist synonyms/photo areas, incidents, photo areas, compte rendu (+ e-mail). */
final class CleaningAssistantTest extends WebTestCase
{
    use ApiTestTrait;

    private string $admin;
    private string $alice;
    private string $bob;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(DemoMailer::class)->reset();
        static::getContainer()->get('cache.app')->clear();
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $this->bob = 'Bearer '.$this->jwtFor($this->createUser('bob@example.org'));
    }

    /** @return array{0: string, 1: string} place id, cleaning id (assigned to alice) */
    private function cleaning(): array
    {
        $place = $this->api('POST', '/api/places', ['name' => 'Le port'], $this->admin)['id'];
        $lines = $this->api('PUT', "/api/places/$place/cleaning-checklist?details=1", ['items' => [
            ['label' => 'Salle de bain', 'synonyms' => ['sdb', ' douche ', '', 'sdb'], 'photo' => true],
            ['label' => 'Faire les lits', 'photo' => true, 'area' => 'Chambre'],
            'Poubelles',
        ]], $this->admin);
        $this->assertStatus(200);
        self::assertSame([
            ['label' => 'Salle de bain', 'synonyms' => ['sdb', 'douche'], 'photo' => true],
            ['label' => 'Faire les lits', 'photo' => true, 'area' => 'Chambre'],
            ['label' => 'Poubelles'],
        ], $lines);
        // Plain view unchanged (labels only).
        self::assertSame(['Salle de bain', 'Faire les lits', 'Poubelles'], $this->api('GET', "/api/places/$place/cleaning-checklist", null, $this->admin));
        $id = $this->api('POST', "/api/places/$place/cleanings", ['scheduledAt' => (new \DateTimeImmutable('today 10:00'))->format(\DATE_ATOM), 'assigneeEmail' => 'alice@example.org'], $this->admin)['id'];

        return [$place, $id];
    }

    public function testChecklistDetailsAreCopiedIntoTheCleaning(): void
    {
        [, $id] = $this->cleaning();
        $task = $this->api('GET', "/api/cleanings/$id", null, $this->alice);
        self::assertSame(['label' => 'Salle de bain', 'done' => false, 'synonyms' => ['sdb', 'douche'], 'photo' => true], $task['checklist'][0]);
        self::assertSame('Chambre', $task['checklist'][1]['area']);
        self::assertSame(['label' => 'Poubelles', 'done' => false], $task['checklist'][2]);
        self::assertSame([], $task['incidents']);
        self::assertFalse($task['hasReport']);
    }

    public function testIncidentsPhotosAndReport(): void
    {
        [, $id] = $this->cleaning();
        $this->api('PATCH', "/api/cleanings/$id", ['status' => 'in_progress', 'checklist' => [['index' => 0, 'done' => true]]], $this->alice);
        $task = $this->api('PATCH', "/api/cleanings/$id", ['incident' => 'La poignée de la douche est cassée'], $this->alice);
        $this->assertStatus(200);
        self::assertSame('La poignée de la douche est cassée', $task['incidents'][0]['text']);
        $this->api('PATCH', "/api/cleanings/$id", ['incident' => '  '], $this->alice);
        $this->assertStatus(422);

        $png = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->client->request('POST', "/api/cleanings/$id/photos", ['moment' => 'after', 'area' => 'Chambre'], ['file' => new UploadedFile($png, 'x.png', 'image/png', null, true)], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $this->alice]);
        $this->assertStatus(201);
        self::assertSame('Chambre', json_decode((string) $this->client->getResponse()->getContent(), true)['photos'][0]['area']);

        // Before completion: a draft; someone else's cleaning: forbidden.
        self::assertTrue($this->api('GET', "/api/cleanings/$id/report", null, $this->alice)['draft']);
        $this->api('GET', "/api/cleanings/$id/report", null, $this->bob);
        $this->assertStatus(403);

        $task = $this->api('PATCH', "/api/cleanings/$id", ['status' => 'done'], $this->alice);
        self::assertTrue($task['hasReport']);
        $report = $this->api('GET', "/api/cleanings/$id/report", null, $this->admin);
        $this->assertStatus(200);
        self::assertArrayNotHasKey('draft', $report);
        self::assertSame(['total' => 3, 'done' => ['Salle de bain'], 'skipped' => ['Faire les lits', 'Poubelles']], $report['checklist']);
        self::assertSame('La poignée de la douche est cassée', $report['incidents'][0]['text']);
        self::assertCount(1, $report['photos']);
        self::assertIsInt($report['durationMinutes']);
        self::assertNotNull($report['completedAt']);
        // The report e-mail is off by default.
        self::assertSame([], array_filter(static::getContainer()->get(DemoMailer::class)->sent(), static fn (array $m) => str_starts_with($m['subject'], 'Compte rendu')));

        // Reopened: the report goes away until completed again.
        self::assertFalse($this->api('PATCH', "/api/cleanings/$id", ['status' => 'in_progress'], $this->alice)['hasReport']);
    }

    public function testReportEmailWhenEnabledAndPublicLink(): void
    {
        [, $id] = $this->cleaning();
        $this->api('PUT', '/api/cleaning-settings', ['report' => true], $this->admin);
        $token = substr($this->api('GET', "/api/cleanings/$id/link", null, $this->admin)['path'], 3);
        static::getContainer()->get(DemoMailer::class)->reset();

        $view = $this->api('PATCH', "/api/public/cleaning/$token", ['status' => 'in_progress', 'incident' => 'Ampoule grillée']);
        $this->assertStatus(200);
        self::assertSame('Ampoule grillée', $view['incidents'][0]['text']);
        $this->api('PATCH', "/api/public/cleaning/$token", ['status' => 'done']);
        $report = $this->api('GET', "/api/public/cleaning/$token/report");
        $this->assertStatus(200);
        self::assertSame(3, $report['checklist']['total']);

        $mails = array_values(array_filter(static::getContainer()->get(DemoMailer::class)->sent(), static fn (array $m) => str_starts_with($m['subject'], 'Compte rendu')));
        self::assertCount(1, $mails);
        self::assertSame(['admin@example.org'], $mails[0]['to']);
        self::assertStringContainsString('Ampoule grillée', $mails[0]['htmlBody']);
    }
}
