<?php

namespace App\Tests\Unit;

use App\Cloud\CloudClient;
use App\Cloud\DemoCloud;
use PHPUnit\Framework\TestCase;
use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\ServiceTokenProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Clean → Rocket Cloud: token of Rocket Auth in suite mode, static ROCKET_CLOUD_TOKEN otherwise. No network. */
final class CloudClientSuiteTokenTest extends TestCase
{
    /** @var list<string> */
    private array $authorizations = [];

    private function client(string $token, ?ServiceTokenProvider $tokens, int $status = 200): CloudClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($status): MockResponse {
            foreach ($options['headers'] as $header) {
                if (str_starts_with($header, 'Authorization: ')) {
                    $this->authorizations[] = substr($header, 15);
                }
            }

            return new MockResponse('[]', ['http_code' => $status]);
        });

        return new CloudClient($http, new DemoCloud(sys_get_temp_dir().'/clean-demo-cloud-'.bin2hex(random_bytes(4)).'.json'), 'http://cloud.test', $token, $tokens);
    }

    private function provider(bool $available, ?string $token = 'suite-token', bool $mock = false): ServiceTokenProvider
    {
        $tokens = $mock ? $this->createMock(ServiceTokenProvider::class) : $this->createStub(ServiceTokenProvider::class);
        $tokens->method('isAvailable')->willReturn($available);
        if (null === $token) {
            $tokens->method('tokenForClient')->willThrowException(new OidcException('down'));
        } else {
            $tokens->method('tokenForClient')->willReturn($token);
        }

        return $tokens;
    }

    public function testStandaloneUsesStaticToken(): void
    {
        $client = $this->client('rca_static', $this->provider(false));
        $client->list('f1');
        self::assertSame(['Bearer rca_static', 'Bearer rca_static'], $this->authorizations);
    }

    public function testSuiteUsesRocketAuthTokenWithoutStaticToken(): void
    {
        $client = $this->client('', $this->provider(true));
        self::assertFalse($client->isDemo());
        $client->list('f1');
        self::assertSame(['Bearer suite-token', 'Bearer suite-token'], $this->authorizations);
    }

    public function testFallsBackToStaticTokenWhenRocketAuthFails(): void
    {
        $client = $this->client('rca_static', $this->provider(true, null));
        $client->list('f1');
        self::assertSame('Bearer rca_static', $this->authorizations[0]);
    }

    public function testNoTokenAtAllIsDemo(): void
    {
        self::assertTrue($this->client('', $this->provider(false))->isDemo());
        self::assertTrue($this->client('', null)->isDemo());
    }

    public function testRefusedSuiteTokenIsForgotten(): void
    {
        $tokens = $this->provider(true, 'suite-token', true);
        $tokens->expects(self::once())->method('forget')->with('rocket-cloud');
        $this->expectException(HttpException::class);
        $this->client('', $tokens, 401)->list('f1');
    }
}
