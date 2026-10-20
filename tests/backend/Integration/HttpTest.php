<?php

declare(strict_types=1);

namespace BlindTyper\Tests\Integration;

final class HttpTest extends DatabaseTestCase
{
    private static $server;
    private static string $base;
    private static string $log;
    private array $cookies = [];
    private ?string $csrf = null;
    private array $headers = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $root = dirname(__DIR__, 3);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$base = 'http://' . $address;
        $rate = $root . '/storage/test/rate-limits';
        if (is_dir($rate)) {
            foreach (glob($rate . '/*.json') as $file) {
                unlink($file);
            }
        }
        self::$log = tempnam(sys_get_temp_dir(), 'blindtyper-http-');
        self::$server = proc_open(
            [PHP_BINARY, '-S', $address, '-t', 'public', 'public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$log, 'a'], 2 => ['file', self::$log, 'a']],
            $pipes,
            $root
        );
        fclose($pipes[0]);
        $ready = false;
        for ($i = 0; $i < 100; $i++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $errstr, .05);
            if ($probe) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        if (!$ready) {
            self::tearDownAfterClass();
            throw new \RuntimeException('HTTP server did not start');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (isset(self::$log)) {
            unlink(self::$log);
        }
    }

    private function request(string $method, string $path, mixed $body = null, bool $sendCsrf = true, ?string $raw = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($sendCsrf && $this->csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($this->cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => $k . '=' . $v, array_keys($this->cookies), $this->cookies));
        }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $raw ?? ($body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)), 'ignore_errors' => true, 'timeout' => 10]]);
        $response = file_get_contents(self::$base . '/api/v1' . $path, false, $context);
        $this->headers = $http_response_header;
        preg_match('~HTTP/\S+ (\d+)~', $this->headers[0], $match);
        foreach ($this->headers as $header) {
            if (preg_match('~^Set-Cookie: ([^=]+)=([^;]*)~i', $header, $cookie)) {
                $this->cookies[$cookie[1]] = $cookie[2];
            }
        }
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail($path . ' status=' . $match[1] . ' body=' . substr($response, 0, 1200));
        }
        return [(int) $match[1], $decoded];
    }

    private function token(): void
    {
        [$status,$body] = $this->request('GET', '/auth/csrf');
        self::assertSame(200, $status);
        $this->csrf = $body['data']['csrf_token'];
    }

    public function testAuthenticationTrainingHistoryAndCrossDeviceLogin(): void
    {
        self::assertSame(401, $this->request('GET', '/me')[0]);
        $this->token();
        $oldToken = $this->csrf;
        [$status,$body] = $this->request('POST', '/auth/register', ['email' => ' Player@Example.org ', 'password' => 'test-password', 'display_name' => 'Игрок']);
        self::assertSame(201, $status, json_encode($body));
        self::assertSame('player@example.org', $body['data']['email']);
        self::assertArrayNotHasKey('password_hash', $body['data']);
        self::assertStringContainsString('HttpOnly', implode(' ', $this->headers));
        self::assertStringContainsString('SameSite=Lax', implode(' ', $this->headers));
        self::assertSame(403, $this->request('POST', '/sessions', ['mode' => 'words'])[0]);
        $this->token();
        self::assertNotSame($oldToken, $this->csrf);
        self::assertSame(422, $this->request('POST', '/auth/register', ['email' => 'PLAYER@example.org', 'password' => 'test-password', 'display_name' => 'Другой'])[0]);
        [$status,$body] = $this->request('POST', '/sessions', ['mode' => 'words', 'language' => 'ru', 'difficulty' => 'easy', 'length' => 120]);
        self::assertSame(201, $status, json_encode($body));
        $session = $body['data'];
        self::assertSame(200, $this->request('POST', '/sessions/' . $session['public_id'] . '/start', new \stdClass())[0]);
        $payload = ['events' => self::events($session['text'], 1000)];
        [$status,$finished] = $this->request('POST', '/sessions/' . $session['public_id'] . '/finish', $payload);
        self::assertSame(200, $status, json_encode($finished));
        self::assertSame('finished', $finished['data']['status']);
        self::assertEquals($finished, $this->request('POST', '/sessions/' . $session['public_id'] . '/finish', $payload)[1]);
        self::assertSame(1, $this->request('GET', '/sessions')[1]['data']['total']);
        self::assertSame(1, $this->request('GET', '/me/stats?days=7')[1]['data']['totals']['training_count']);
        self::assertSame(3, count($this->request('GET', '/me/achievements')[1]['data']['items']));
        self::assertSame(200, $this->request('GET', '/me/bonuses')[0]);
        $xp = $this->request('GET', '/me')[1]['data']['total_xp'];
        self::assertSame(200, $this->request('POST', '/auth/logout', new \stdClass())[0]);
        self::assertSame(401, $this->request('GET', '/me')[0]);
        $this->cookies = [];
        $this->csrf = null;
        $this->token();
        self::assertSame(401, $this->request('POST', '/auth/login', ['email' => 'player@example.org', 'password' => 'wrong-password'])[0]);
        self::assertSame(200, $this->request('POST', '/auth/login', ['email' => 'player@example.org', 'password' => 'test-password'])[0]);
        self::assertSame($xp, $this->request('GET', '/me')[1]['data']['total_xp']);
        $file = dirname(__DIR__, 3) . '/storage/test/sessions/sess_' . $this->cookies['blindtyper_session'];
        $saved = file_get_contents($file);
        $expired = preg_replace('/last_activity\|i:[0-9]+;/', 'last_activity|i:' . (time() - 86401) . ';', $saved);
        self::assertNotSame($saved, $expired);
        file_put_contents($file, $expired);
        self::assertSame(401, $this->request('GET', '/me')[0]);

    }

    public function testProtocolValidationAndPublicRoutes(): void
    {
        self::assertSame(200, $this->request('GET', '/catalog')[0]);
        self::assertSame(200, $this->request('GET', '/leaderboard?metric=xp')[0]);
        foreach (['/leaderboard?metric=wrong', '/leaderboard?page%5B%5D=1', '/leaderboard?page=-1', '/leaderboard?per_page=101'] as $path) {
            self::assertSame(422, $this->request('GET', $path)[0]);
        }
        self::assertSame(404, $this->request('GET', '/does-not-exist')[0]);
        self::assertSame(400, $this->request('POST', '/auth/login', null, false, '{')[0]);
        self::assertSame(422, $this->request('POST', '/auth/login', null, false, '[]')[0]);
        self::assertSame(413, $this->request('POST', '/auth/login', null, false, str_repeat('x', 2097153))[0]);
        $this->token();
        self::assertSame(403, $this->request('POST', '/auth/login', ['email' => 'p@example.org', 'password' => 'test-password'], false)[0]);
    }

    public function testLoginRateLimitPersistsAcrossNewCookies(): void
    {
        $this->token();
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(401, $this->request('POST', '/auth/login', ['email' => 'limited@example.org', 'password' => 'wrong-password'])[0]);
        }
        $this->cookies = [];
        $this->csrf = null;
        $this->token();
        self::assertSame(429, $this->request('POST', '/auth/login', ['email' => 'limited@example.org', 'password' => 'wrong-password'])[0]);
    }
}
