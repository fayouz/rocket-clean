<?php

namespace App\Tests\Functional;

use App\Cleaning\RecurrenceGenerator;
use App\Entity\CleaningRecurrence;
use App\Tests\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Types, origin, checklist per type, occupancy conflicts, costs and export, recurrences. No network. */
final class CleaningTypesTest extends WebTestCase
{
    use ApiTestTrait;

    private string $admin;
    private string $alice;
    private string $place;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->alice = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
        $this->place = $this->api('POST', '/api/places', ['name' => 'Le port'], $this->admin)['id'];
    }

    private function at(string $when): string
    {
        return (new \DateTimeImmutable($when))->format(\DATE_ATOM);
    }

    public function testTypeOriginAndApplicationOwnership(): void
    {
        [, $pms] = $this->createApplication(false, 'Rocket PMS');
        [, $host] = $this->createApplication(false, 'Rocket Host');
        $rental = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+1 day 11:00'), 'externalRef' => 'booking:42:checkout'], 'Bearer '.$pms);
        $this->assertStatus(201);
        self::assertSame(['rental', 'pms', 'Rocket PMS'], [$rental['type'], $rental['origin'], $rental['originApp']]);
        $hosted = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+2 days 11:00'), 'externalRef' => 'stay-7', 'origin' => 'host'], 'Bearer '.$host);
        self::assertSame(['personal', 'host', 'Rocket Host'], [$hosted['type'], $hosted['origin'], $hosted['originApp']]);
        $mine = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+1 day 15:00'), 'type' => 'maintenance'], $this->admin);
        self::assertSame(['maintenance', 'clean', null], [$mine['type'], $mine['origin'], $mine['originApp']]);
        $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+1 day'), 'type' => 'party'], $this->admin);
        $this->assertStatus(422);

        self::assertSame([$rental['id']], array_column($this->api('GET', "/api/places/{$this->place}/cleanings?type=rental", null, $this->alice), 'id'));
        self::assertCount(1, $this->api('GET', '/api/cleanings?date=all&type=maintenance', null, $this->alice));
        $this->api('GET', '/api/cleanings?type=nope', null, $this->alice);
        $this->assertStatus(422);

        // An application keeps editing its own tasks and people's, never another application's.
        $this->api('PATCH', "/api/cleanings/{$rental['id']}", ['dueAt' => $this->at('+1 day 16:00')], 'Bearer '.$pms);
        $this->assertStatus(200);
        $this->api('PATCH', "/api/cleanings/{$rental['id']}", ['dueAt' => $this->at('+1 day 17:00')], 'Bearer '.$host);
        $this->assertStatus(403);
        $this->api('DELETE', "/api/cleanings/{$rental['id']}", null, 'Bearer '.$host);
        $this->assertStatus(403);
        $this->api('PATCH', "/api/cleanings/{$mine['id']}", ['label' => 'Chaudière'], 'Bearer '.$host);
        $this->assertStatus(200);
    }

    public function testChecklistPerType(): void
    {
        $this->api('PUT', "/api/places/{$this->place}/cleaning-checklist", ['items' => ['Draps']], $this->admin);
        $this->api('PUT', "/api/places/{$this->place}/cleaning-checklist?type=maintenance", ['items' => ['Filtre VMC', 'Détartrage']], $this->admin);
        self::assertSame(['Filtre VMC', 'Détartrage'], $this->api('GET', "/api/places/{$this->place}/cleaning-checklist?type=maintenance", null, $this->alice));
        self::assertSame(['Draps'], $this->api('GET', "/api/places/{$this->place}/cleaning-checklist", null, $this->alice));

        $maintenance = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+1 day'), 'type' => 'maintenance'], $this->admin);
        self::assertSame(['Filtre VMC', 'Détartrage'], array_column($maintenance['checklist'], 'label'));
        $rental = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+1 day'), 'type' => 'rental'], $this->admin);
        self::assertSame(['Draps'], array_column($rental['checklist'], 'label'));
        // No template for "personal": the rental (default) one is used.
        $personal = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+1 day')], $this->admin);
        self::assertSame(['Draps'], array_column($personal['checklist'], 'label'));
    }

    public function testOccupancyFlagsConflicts(): void
    {
        [, $pms] = $this->createApplication(false, 'Rocket PMS');
        $personal = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+3 days 10:00'), 'dueAt' => $this->at('+3 days 12:00')], $this->admin);
        $rental = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+3 days 10:00'), 'type' => 'rental'], $this->admin);
        self::assertFalse($personal['conflict']);

        $periods = [['from' => $this->at('+2 days 16:00'), 'until' => $this->at('+4 days 11:00'), 'externalRef' => 'booking:9']];
        self::assertCount(1, $this->api('PUT', "/api/places/{$this->place}/occupancy", $periods, 'Bearer '.$pms));
        $this->assertStatus(200);
        self::assertTrue($this->api('GET', "/api/cleanings/{$personal['id']}", null, $this->alice)['conflict']);
        self::assertFalse($this->api('GET', "/api/cleanings/{$rental['id']}", null, $this->alice)['conflict']);
        $later = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => $this->at('+3 days 18:00'), 'type' => 'maintenance'], $this->admin);
        self::assertTrue($later['conflict']);
        self::assertSame('booking:9', $this->api('GET', "/api/places/{$this->place}/occupancy", null, $this->alice)[0]['externalRef']);

        // Moving the task out of the stay clears the flag; so does an empty occupancy.
        self::assertFalse($this->api('PATCH', "/api/cleanings/{$later['id']}", ['scheduledAt' => $this->at('+5 days 10:00')], $this->admin)['conflict']);
        $this->api('PUT', "/api/places/{$this->place}/occupancy", ['periods' => []], $this->admin);
        self::assertFalse($this->api('GET', "/api/cleanings/{$personal['id']}", null, $this->alice)['conflict']);

        $this->api('PUT', "/api/places/{$this->place}/occupancy", [['from' => $this->at('+2 days'), 'until' => $this->at('+1 day')]], $this->admin);
        $this->assertStatus(422);
        $this->api('PUT', "/api/places/{$this->place}/occupancy", [], $this->alice);
        $this->assertStatus(403);
    }

    public function testCostsAndExport(): void
    {
        self::assertSame(['rental' => 4500, 'personal' => null, 'maintenance' => 8000], $this->api('PUT', "/api/places/{$this->place}/cleaning-costs", ['rental' => 4500, 'maintenance' => 8000], $this->admin));
        $this->api('PUT', "/api/places/{$this->place}/cleaning-costs", ['rental' => -1], $this->admin);
        $this->assertStatus(422);

        $a = $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => '2026-10-05T11:00:00+02:00', 'type' => 'rental'], $this->admin);
        self::assertSame(4500, $a['cost']);
        $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => '2026-10-12T11:00:00+02:00', 'type' => 'rental', 'cost' => 6000], $this->admin);
        $this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => '2026-10-06T11:00:00+02:00', 'type' => 'maintenance'], $this->admin);
        self::assertNull($this->api('POST', "/api/places/{$this->place}/cleanings", ['scheduledAt' => '2026-10-07T11:00:00+02:00'], $this->admin)['cost']);
        $this->api('PATCH', "/api/cleanings/{$a['id']}", ['cost' => 5000], $this->admin);

        $export = $this->api('GET', '/api/cleanings/export?type=rental&from=2026-10-01&to=2026-10-31', null, $this->admin);
        $this->assertStatus(200);
        self::assertSame([2, 11000], [$export['count'], $export['total']]);
        self::assertSame(['rental'], array_values(array_unique(array_column($export['items'], 'type'))));
        self::assertSame(1, $this->api('GET', '/api/cleanings/export?type=rental&from=2026-10-01&to=2026-10-05', null, $this->admin)['count']);
        $this->api('GET', '/api/cleanings/export', null, $this->alice);
        $this->assertStatus(403);
        [, $host] = $this->createApplication(false, 'Rocket Host');
        $this->api('GET', '/api/cleanings/export?type=rental', null, 'Bearer '.$host);
        $this->assertStatus(200);
    }

    public function testRecurrenceGeneratesAheadIdempotently(): void
    {
        $alice = $this->em()->getRepository(\Rocket\Core\Entity\User::class)->findOneBy(['email' => 'alice@example.org']);
        $this->api('POST', "/api/places/{$this->place}/recurrences", ['frequency' => 'weekly', 'weekdays' => [8]], $this->admin);
        $this->assertStatus(422);
        $this->api('POST', "/api/places/{$this->place}/recurrences", ['frequency' => 'monthly', 'monthDay' => 3, 'nth' => 1, 'nthWeekday' => 1], $this->admin);
        $this->assertStatus(422);

        $r = $this->api('POST', "/api/places/{$this->place}/recurrences", ['frequency' => 'weekly', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'time' => '06:00', 'type' => 'maintenance', 'label' => 'Aération', 'durationMinutes' => 30, 'assigneeEmail' => 'alice@example.org', 'cost' => 1500, 'checklist' => ['Ouvrir']], $this->admin);
        $this->assertStatus(201);
        self::assertSame('alice@example.org', $r['assignee']['email']);
        $tasks = array_values(array_filter($this->api('GET', "/api/places/{$this->place}/cleanings", null, $this->alice), static fn (array $t) => 'recurrence' === $t['origin']));
        self::assertGreaterThanOrEqual(14, \count($tasks));
        self::assertLessThanOrEqual(15, \count($tasks));
        self::assertSame(['maintenance', 'Aération', 1500, ['Ouvrir']], [$tasks[0]['type'], $tasks[0]['label'], $tasks[0]['cost'], array_column($tasks[0]['checklist'], 'label')]);
        self::assertStringStartsWith("recurrence:{$r['id']}:", $tasks[0]['externalRef']);
        self::assertNotNull($alice);
        self::assertSame(0, static::getContainer()->get(RecurrenceGenerator::class)->generate());
        self::assertCount(1, $this->api('GET', "/api/places/{$this->place}/recurrences", null, $this->alice));

        // Weekly on one day only: the future cleanings still to do are regenerated.
        $this->api('PATCH', "/api/recurrences/{$r['id']}", ['weekdays' => [1]], $this->admin);
        $this->assertStatus(200);
        $count = \count(array_filter($this->api('GET', "/api/places/{$this->place}/cleanings", null, $this->alice), static fn (array $t) => 'recurrence' === $t['origin']));
        self::assertGreaterThanOrEqual(2, $count);
        self::assertLessThanOrEqual(3, $count);

        $this->api('DELETE', "/api/recurrences/{$r['id']}", null, $this->admin);
        $this->assertStatus(200);
        self::assertCount(0, array_filter($this->api('GET', "/api/places/{$this->place}/cleanings", null, $this->alice), static fn (array $t) => 'recurrence' === $t['origin'] && 'todo' === $t['status'] && $t['scheduledAt'] > date(\DATE_ATOM)));
    }

    public function testMonthlyRules(): void
    {
        $r = new CleaningRecurrence($this->place, 'Le port', new \DateTimeImmutable('2026-01-01'));
        $r->setMonthly(null, -1, 6); // last Saturday
        self::assertTrue($r->matches(new \DateTimeImmutable('2026-10-31')));
        self::assertFalse($r->matches(new \DateTimeImmutable('2026-10-24')));
        $r->setMonthly(null, 1, 1); // first Monday
        self::assertTrue($r->matches(new \DateTimeImmutable('2026-10-05')));
        self::assertFalse($r->matches(new \DateTimeImmutable('2026-10-12')));
        $r->setMonthly(15, null, null);
        self::assertTrue($r->matches(new \DateTimeImmutable('2026-11-15')));
        $r->setEndsOn(new \DateTimeImmutable('2026-11-01'));
        self::assertFalse($r->matches(new \DateTimeImmutable('2026-11-15')));
        self::assertFalse($r->matches(new \DateTimeImmutable('2025-12-15')));
    }
}
