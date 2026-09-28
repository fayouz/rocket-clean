<?php

namespace App\Tests\Unit;

use App\Entity\CleaningTask;
use App\Place\PlaceClient;
use App\Place\PlaceDirectory;
use App\Repository\SiteRepository;
use App\Stock\CleaningStock;
use App\Stock\StockClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Stock reports of a cleaning: Rocket Stock (consumption + state), else Rocket Place (state), else none. No network. */
final class CleaningStockTest extends TestCase
{
    private const PLACE = '0192f7c4-0000-7000-8000-000000000001';
    private const LEVEL = '0192f7c4-0000-7000-8000-0000000000aa';

    /** @var list<array{method: string, url: string, body: string, auth: string}> */
    private array $calls = [];

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $u, array $options): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $u, 'body' => (string) ($options['body'] ?? ''), 'auth' => implode(',', $options['headers'] ?? [])];
            $path = (string) parse_url($u, \PHP_URL_PATH);
            $json = match (true) {
                '/api/places/'.self::PLACE.'/stock' === $path => [['id' => self::LEVEL, 'place' => '/api/places/'.self::PLACE, 'item' => '/api/stock-items/i1', 'name' => 'Café', 'level' => 'ok', 'location' => ['id' => 'l1', 'placeId' => self::PLACE, 'name' => 'Cuisine']]],
                '/api/stock-items' === $path => [['id' => 'i1', 'name' => 'Café']],
                '/api/stock-levels' === $path => [['id' => self::LEVEL, 'place' => '/api/places/'.self::PLACE, 'item' => '/api/stock-items/i1', 'level' => 'ok']],
                '/api/movements' === $path => ['id' => 'm1'],
                '/api/stock-levels/'.self::LEVEL === $path => ['id' => self::LEVEL, 'level' => 'low'],
                default => null,
            };

            return null === $json ? new MockResponse('{"detail":"Introuvable."}', ['http_code' => 404]) : new MockResponse(json_encode($json, \JSON_THROW_ON_ERROR));
        });
    }

    private function stock(string $stockUrl, string $placeUrl): CleaningStock
    {
        $http = $this->http();
        $places = new PlaceDirectory(new PlaceClient($http, $placeUrl, 'rpl_secret'), $this->createStub(SiteRepository::class), $this->createStub(EntityManagerInterface::class));

        return new CleaningStock(new StockClient($http, $stockUrl, 'rst_secret'), $places);
    }

    private function task(?string $ref): CleaningTask
    {
        return new CleaningTask(self::PLACE, 'Le port', 'Ménage', new \DateTimeImmutable(), [], $ref);
    }

    public function testRocketStockRecordsAConsumptionThenTheState(): void
    {
        $stock = $this->stock('http://stock.test', 'http://place.test');
        self::assertSame([['id' => self::LEVEL, 'name' => 'Café', 'level' => 'ok']], $stock->levels(self::PLACE));
        $task = $this->task('booking:3:checkout');
        self::assertSame(['id' => self::LEVEL, 'name' => 'Café', 'level' => 'low'], $stock->report($task, self::LEVEL, 'low'));

        $post = array_values(array_filter($this->calls, static fn (array $c) => 'POST' === $c['method']))[0];
        self::assertSame('http://stock.test/api/movements', $post['url']);
        $body = json_decode($post['body'], true);
        self::assertSame(['consume', 1, 'rental', 'cleaning:'.$task->getId()->toRfc4122().':'.self::LEVEL, 'clean', 'Cuisine', '/api/stock-items/i1', self::PLACE],
            [$body['type'], $body['quantity'], $body['usage'], $body['externalRef'], $body['origin'], $body['location'], $body['item'], $body['placeId']]);
        self::assertStringContainsString('Bearer rst_secret', $post['auth']);
        $patch = array_values(array_filter($this->calls, static fn (array $c) => 'PATCH' === $c['method']))[0];
        self::assertSame(['http://stock.test/api/stock-levels/'.self::LEVEL, '{"level":"low"}'], [$patch['url'], $patch['body']]);
        self::assertStringNotContainsString('place.test', implode(' ', array_column($this->calls, 'url')), 'Rocket Place not used for stock');

        $this->calls = [];
        $stock->report($this->task(null), self::LEVEL, 'empty', 2.5);
        $body = json_decode(array_values(array_filter($this->calls, static fn (array $c) => 'POST' === $c['method']))[0]['body'], true);
        self::assertSame(['personal', 2.5], [$body['usage'], $body['quantity']]);

        // State unchanged ("ok" → "ok") without a quantity: no consumption, only the state
        $this->calls = [];
        $stock->report($this->task('booking:4:checkout'), self::LEVEL, 'ok');
        self::assertSame([], array_filter($this->calls, static fn (array $c) => 'POST' === $c['method']), 'no consumption when nothing dropped');
        self::assertCount(1, array_filter($this->calls, static fn (array $c) => 'PATCH' === $c['method']));
    }

    public function testUnknownLevelIsA404(): void
    {
        $this->expectException(HttpException::class);
        $this->stock('http://stock.test', '')->report($this->task(null), '0192f7c4-0000-7000-8000-0000000000bb', 'low');
    }

    public function testFallbackToRocketPlaceThenNone(): void
    {
        $viaPlace = $this->stock('', 'http://place.test');
        self::assertSame([['id' => self::LEVEL, 'name' => 'Café', 'level' => 'ok']], $viaPlace->levels(self::PLACE));
        $viaPlace->report($this->task(null), self::LEVEL, 'low');
        self::assertSame([], array_filter($this->calls, static fn (array $c) => 'POST' === $c['method']), 'no movement without Rocket Stock');
        self::assertStringContainsString('place.test/api/stock-levels/'.self::LEVEL, implode(' ', array_column($this->calls, 'url')));

        $this->calls = [];
        self::assertSame([], $this->stock('', '')->levels(self::PLACE));
        self::assertSame([], $this->calls);
    }
}
