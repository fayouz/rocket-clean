<?php

namespace App\Tests\Functional;

use App\Mailer\DemoMailer;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Linen module (App\Linen): catalog, counts, cleaning step, washes, laundry batches, readiness, alerts. No network. */
final class LinenTest extends WebTestCase
{
    use ApiTestTrait;

    private string $admin;
    private string $alice;
    private string $bob;
    private string $place;
    /** @var array<string, string> */
    private array $t = [];
    private string $kitLit;
    private string $kitBain;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::getContainer()->get(DemoMailer::class)->reset();
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $this->bob = 'Bearer '.$this->jwtFor($this->createUser('bob@example.org'));
        $this->place = $this->api('POST', '/api/places', ['name' => 'Le port'], $this->admin)['id'];
        foreach (['housse' => 1200, 'drap' => 600, 'taie' => 150, 'serviette' => 600] as $name => $grams) {
            $this->t[$name] = $this->api('POST', '/api/linen/types', ['name' => $name, 'weightGrams' => $grams], $this->admin)['id'];
            $this->assertStatus(201);
        }
        $this->kitLit = $this->api('POST', '/api/linen/kits', ['name' => 'Kit lit double', 'lines' => [['typeId' => $this->t['housse'], 'qty' => 1], ['typeId' => $this->t['drap'], 'qty' => 1], ['typeId' => $this->t['taie'], 'qty' => 2]]], $this->admin)['id'];
        $this->kitBain = $this->api('POST', '/api/linen/kits', ['name' => 'Kit bain', 'lines' => [['typeId' => $this->t['serviette'], 'qty' => 2]]], $this->admin)['id'];
    }

    public function testCatalogNeedsAndInventory(): void
    {
        $this->api('POST', '/api/linen/types', ['name' => 'Torchon'], $this->alice);
        $this->assertStatus(403);
        $this->api('POST', '/api/linen/kits', ['name' => 'Vide', 'lines' => []], $this->admin);
        $this->assertStatus(422);
        self::assertCount(4, $this->api('GET', '/api/linen/types', null, $this->alice));

        $needs = $this->api('PUT', "/api/linen/places/{$this->place}/needs", [['kitId' => $this->kitLit, 'units' => 2, 'kitsPerUnit' => 3], ['kitId' => $this->kitBain, 'units' => 1]], $this->admin);
        $this->assertStatus(200);
        self::assertSame([6, 3], array_column($needs, 'parLevel'));
        $this->api('PUT', '/api/linen/places/0192f7c4-0000-7000-8000-00000000ffff/needs', [], $this->admin);
        $this->assertStatus(404);

        $summary = $this->inventory(['housse' => 4, 'drap' => 4, 'taie' => 8, 'serviette' => 4]);
        self::assertSame(20, $summary['states']['clean']);
        self::assertSame(['Kit lit double' => 4, 'Kit bain' => 2], array_column($summary['kits'], 'cleanKits', 'kitName'));
        // Counting again the same figures changes nothing; a lower count is an exit.
        $this->inventory(['housse' => 4, 'drap' => 3, 'taie' => 8, 'serviette' => 4]);
        $movements = $this->api('GET', "/api/linen/places/{$this->place}/movements", null, $this->alice);
        self::assertCount(5, $movements);
        self::assertSame(['clean', null, 1], [$movements[0]['from'], $movements[0]['to'], $movements[0]['qty']]);

        // Strict manual movement: not more than there is; idempotent by externalRef.
        $this->api('POST', "/api/linen/places/{$this->place}/movements", ['type' => $this->t['housse'], 'from' => 'clean', 'to' => 'lost', 'qty' => 9], $this->admin);
        $this->assertStatus(422);
        $this->api('POST', "/api/linen/places/{$this->place}/movements", ['type' => $this->t['housse'], 'from' => 'clean', 'to' => 'lost', 'qty' => 1, 'externalRef' => 'x-1'], $this->admin);
        $this->assertStatus(201);
        self::assertTrue($this->api('POST', "/api/linen/places/{$this->place}/movements", ['type' => $this->t['housse'], 'from' => 'clean', 'to' => 'lost', 'qty' => 1, 'externalRef' => 'x-1'], $this->admin)['alreadyRecorded']);
        $s = $this->api('GET', "/api/linen/summary?place={$this->place}", null, $this->alice)[0];
        self::assertSame([18, 1], [$s['states']['clean'], $s['states']['lost']]);

        // Replacement through Rocket Stock: explicit action, refused without Stock configured or without a Stock item.
        $rep = $this->api('GET', '/api/linen/replacements', null, $this->alice);
        self::assertFalse($rep['stockConfigured']);
        self::assertSame(1, $rep['items'][0]['lost']);
        $this->api('POST', '/api/linen/replacements', ['placeId' => $this->place, 'type' => $this->t['housse'], 'qty' => 1], $this->admin);
        $this->assertStatus(422);
        $this->api('PATCH', "/api/linen/types/{$this->t['housse']}", ['stockItemId' => '0192f7c4-0000-7000-8000-00000000d001'], $this->admin);
        $this->api('POST', '/api/linen/replacements', ['placeId' => $this->place, 'type' => $this->t['housse'], 'qty' => 1], $this->admin);
        $this->assertStatus(409);
        $this->api('DELETE', "/api/linen/types/{$this->t['housse']}", null, $this->admin);
        $this->assertStatus(409);
    }

    public function testCleaningLinenStepIsIdempotentAndOpenToTheSecretLink(): void
    {
        $this->inventory(['housse' => 2, 'drap' => 2, 'taie' => 4, 'serviette' => 4]);
        $this->api('POST', "/api/linen/places/{$this->place}/movements", ['type' => $this->t['housse'], 'from' => 'clean', 'to' => 'in_use', 'qty' => 1], $this->admin);
        $id = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => (new \DateTimeImmutable('today 11:00'))->format(\DATE_ATOM), 'assigneeEmail' => 'alice@example.org', 'type' => 'rental'], $this->admin)['id'];

        $body = ['key' => 'k1', 'removed' => [['type' => $this->t['housse'], 'qty' => 1]], 'placed' => [['kit' => $this->kitLit, 'qty' => 1]], 'damaged' => [['type' => $this->t['taie'], 'qty' => 1]]];
        $this->api('POST', "/api/cleanings/$id/linen", $body, $this->bob);
        $this->assertStatus(403);
        $r = $this->api('POST', "/api/cleanings/$id/linen", $body, $this->alice);
        $this->assertStatus(201);
        self::assertSame(['k1', 5, false], [$r['key'], $r['applied'], $r['alreadyRecorded']]);
        self::assertSame("cleaning:$id:linen:k1:removed:{$this->t['housse']}", $r['movements'][0]['externalRef']);
        self::assertSame(['clean', 'rental'], [$r['movements'][0]['origin'], $r['movements'][0]['usage']]);
        $again = $this->api('POST', "/api/cleanings/$id/linen", $body, $this->alice);
        $this->assertStatus(200);
        self::assertTrue($again['alreadyRecorded']);

        $s = $this->api('GET', "/api/linen/summary?place={$this->place}", null, $this->alice)[0];
        // housse: 1 clean → in_use by hand, then removed 1 (dirty), placed 1 (in_use); taie: 2 placed, 1 of them damaged (lenient).
        $byName = array_column($s['types'], 'states', 'name');
        self::assertSame(['clean' => 0, 'in_use' => 1, 'dirty' => 1], array_intersect_key($byName['housse'], ['clean' => 1, 'in_use' => 1, 'dirty' => 1]));
        self::assertSame(['clean' => 2, 'in_use' => 1, 'damaged' => 1], array_intersect_key($byName['taie'], ['clean' => 1, 'in_use' => 1, 'damaged' => 1]));

        // Same step through the secret link, without account; no key: numbered.
        $token = substr($this->api('GET', "/api/cleanings/$id/link", null, $this->admin)['path'], 3);
        $view = $this->api('GET', "/api/public/cleaning/$token/linen");
        $this->assertStatus(200);
        self::assertCount(2, $view['kits']);
        self::assertCount(5, $view['movements']);
        $r = $this->api('POST', "/api/public/cleaning/$token/linen", ['removed' => [['kit' => $this->kitBain, 'qty' => 1]]]);
        $this->assertStatus(201);
        self::assertSame('1', $r['key']);
        $this->api('POST', "/api/public/cleaning/$token/linen", ['removed' => []]);
        $this->assertStatus(422);
    }

    public function testWashLaundryBatchEmailAndCosts(): void
    {
        $this->inventory(['housse' => 0, 'drap' => 0, 'taie' => 0, 'serviette' => 0], 'dirty', ['housse' => 4, 'drap' => 4, 'taie' => 8, 'serviette' => 6]);
        $alice = $this->api('GET', '/api/cleaning-assignees', null, $this->admin)[1]['id'];
        $wash = $this->api('POST', '/api/linen/washes', ['placeId' => $this->place, 'scheduledAt' => (new \DateTimeImmutable('today 14:00'))->format(\DATE_ATOM), 'lines' => [['type' => $this->t['serviette'], 'qty' => 6]], 'assigneeId' => $alice], $this->admin);
        $this->assertStatus(201);
        self::assertSame('alice@example.org', $wash['assignee']['email']);
        self::assertCount(1, $this->api('GET', '/api/linen/washes?mine=1&date='.date('Y-m-d'), null, $this->alice));
        $this->api('PATCH', "/api/linen/washes/{$wash['id']}", ['steps' => ['machine' => true]], $this->bob);
        $this->assertStatus(403);
        self::assertTrue($this->api('PATCH', "/api/linen/washes/{$wash['id']}", ['steps' => ['machine' => true]], $this->alice)['steps']['machine']);
        $done = $this->api('PATCH', "/api/linen/washes/{$wash['id']}", ['status' => 'done'], $this->alice);
        self::assertSame(['done', true], [$done['status'], $done['steps']['pliage']]);
        $this->api('PATCH', "/api/linen/washes/{$wash['id']}", ['status' => 'done'], $this->alice);
        $s = $this->api('GET', "/api/linen/summary?place={$this->place}", null, $this->alice)[0];
        self::assertSame(['clean' => 6, 'dirty' => 0], array_intersect_key(array_column($s['types'], 'states', 'name')['serviette'], ['clean' => 1, 'dirty' => 1]));

        $laundry = $this->api('POST', '/api/linen/laundries', ['name' => 'Blanchisserie du Port', 'orderEmail' => 'commandes@blanchisserie.example', 'pricing' => 'kg', 'pricePerKg' => 450, 'turnaroundDays' => 3], $this->admin);
        $this->assertStatus(201);
        $batch = $this->api('POST', '/api/linen/batches', ['laundryId' => $laundry['id'], 'placeId' => $this->place, 'lines' => [['kit' => $this->kitLit, 'qty' => 4]], 'usage' => 'personal'], $this->alice);
        $this->assertStatus(201);
        self::assertSame(['sent', 8400, false], [$batch['status'], $batch['weightGrams'], $batch['overdue']]); // 4 × (1200 + 600 + 2 × 150) g
        self::assertSame(16, $this->api('GET', "/api/linen/summary?place={$this->place}", null, $this->alice)[0]['states']['at_laundry']);

        // E-mail: preview, then sent only when confirmed, once.
        $preview = $this->api('GET', "/api/linen/batches/{$batch['id']}/email", null, $this->alice);
        self::assertSame(['commandes@blanchisserie.example'], $preview['to']);
        self::assertStringContainsString('housse', $preview['htmlBody']);
        self::assertCount(0, static::getContainer()->get(DemoMailer::class)->sent());
        $this->api('POST', "/api/linen/batches/{$batch['id']}/email", [], $this->admin);
        $this->assertStatus(422);
        $this->api('POST', "/api/linen/batches/{$batch['id']}/email", ['confirm' => true], $this->alice);
        $this->assertStatus(403);
        self::assertNotNull($this->api('POST', "/api/linen/batches/{$batch['id']}/email", ['confirm' => true], $this->admin)['sentAt']);
        self::assertTrue($this->api('POST', "/api/linen/batches/{$batch['id']}/email", ['confirm' => true], $this->admin)['alreadySent']);
        self::assertCount(1, static::getContainer()->get(DemoMailer::class)->sent());

        // Return counted: 1 taie damaged, 1 taie and 1 drap missing.
        $this->api('POST', "/api/linen/batches/{$batch['id']}/return", ['returned' => [['type' => $this->t['serviette'], 'qty' => 1]]], $this->alice);
        $this->assertStatus(422);
        $back = $this->api('POST', "/api/linen/batches/{$batch['id']}/return", ['returned' => [['type' => $this->t['housse'], 'qty' => 4], ['type' => $this->t['drap'], 'qty' => 3], ['type' => $this->t['taie'], 'qty' => 6]], 'damaged' => [['type' => $this->t['taie'], 'qty' => 1]], 'weightKg' => 11.5], $this->alice);
        $this->assertStatus(200);
        self::assertSame(['returned', 5175], [$back['status'], $back['cost']]); // 11.5 kg × 4.50 €
        self::assertSame([['drap', 1, 0], ['taie', 1, 1]], array_map(static fn ($d) => [$d['typeName'], $d['missing'], $d['damaged']], $back['discrepancies']));
        $this->api('POST', "/api/linen/batches/{$batch['id']}/return", ['returned' => []], $this->alice);
        $this->assertStatus(409);
        $s = $this->api('GET', "/api/linen/summary?place={$this->place}", null, $this->alice)[0]['states'];
        self::assertSame([0, 2, 1], [$s['at_laundry'], $s['lost'], $s['damaged']]);

        $costs = $this->api('GET', '/api/linen/costs', null, $this->alice);
        self::assertSame(['rental' => 0, 'personal' => 5175, 'total' => 5175], $costs['totals']);
        $this->client->request('GET', '/api/linen/costs?format=csv', server: ['HTTP_AUTHORIZATION' => $this->alice]);
        self::assertStringContainsString(';personal;', (string) $this->client->getResponse()->getContent());
        self::assertSame(['losses'], array_column($this->api('GET', '/api/linen/alerts', null, $this->alice), 'type'));
    }

    public function testReadinessForHostAndAlerts(): void
    {
        [, $host] = $this->createApplication(false, 'Rocket Host');
        $host = 'Bearer '.$host;
        $this->api('PUT', "/api/linen/places/{$this->place}/needs", [['kitId' => $this->kitLit, 'units' => 1], ['kitId' => $this->kitBain, 'units' => 1]], $host);
        $this->assertStatus(200);
        $this->inventory(['housse' => 2, 'drap' => 3, 'taie' => 4, 'serviette' => 2]);
        $at = static fn (string $d) => (new \DateTimeImmutable($d))->format(\DATE_ATOM);
        $this->api('PUT', "/api/places/{$this->place}/occupancy", [['from' => $at('+1 day 16:00'), 'until' => $at('+3 days 10:00'), 'externalRef' => 'stay-1'], ['from' => $at('+4 days 16:00'), 'until' => $at('+6 days 10:00'), 'externalRef' => 'stay-2']], $host);
        $this->assertStatus(200);

        $r = $this->api('GET', "/api/linen/readiness?place={$this->place}", null, $host);
        $this->assertStatus(200);
        $arrivals = $r[0]['arrivals'];
        self::assertSame(['stay-1', 'stay-2'], array_column($arrivals, 'externalRef'));
        // 2 kits lit (ready for the 1st, tight for the 2nd), 1 kit bain (tight, then missing).
        self::assertSame(['tight', 'missing'], array_column($arrivals, 'status'));
        self::assertSame([['ready', 2, 1], ['tight', 1, 1]], array_map(static fn ($k) => [$k['status'], $k['available'], $k['needed']], $arrivals[0]['kits']));
        self::assertSame([], $this->api('GET', "/api/linen/readiness?place={$this->place}&date=2020-01-01&days=3", null, $host)[0]['arrivals']);
        $this->api('GET', "/api/linen/readiness?days=99", null, $host);
        $this->assertStatus(422);

        $alerts = $this->api('GET', '/api/linen/alerts', null, $host);
        self::assertSame([['kits', 'warning'], ['kits', 'error']], array_map(static fn ($a) => [$a['type'], $a['level']], $alerts));
        self::assertSame('host', $this->api('POST', "/api/linen/places/{$this->place}/movements", ['type' => $this->t['drap'], 'from' => 'clean', 'to' => 'damaged', 'qty' => 1], $host)['origin']);
        // An application does not reach the rest (assignees stay guarded by rocket-core).
        $this->api('GET', '/api/cleaning-assignees', null, $host);
        $this->assertStatus(403);

        $dash = $this->api('GET', '/api/dashboard', null, $this->admin);
        self::assertContains('linen_kits_short', array_column($dash['kpis'], 'id'));
    }

    /**
     * @param array<string, int>      $qty
     * @param array<string, int>|null $second quantities of a second state
     *
     * @return array<string, mixed>
     */
    private function inventory(array $qty, string $state = 'clean', ?array $second = null): array
    {
        $lines = [];
        foreach ($qty as $k => $n) {
            $lines[] = ['type' => $this->t[$k], 'state' => 'dirty' === $state ? 'clean' : $state, 'qty' => $n];
        }
        foreach ($second ?? [] as $k => $n) {
            $lines[] = ['type' => $this->t[$k], 'state' => $state, 'qty' => $n];
        }
        $summary = $this->api('PUT', "/api/linen/places/{$this->place}/counts", $lines, $this->admin);
        $this->assertStatus(200);

        return $summary;
    }
}
