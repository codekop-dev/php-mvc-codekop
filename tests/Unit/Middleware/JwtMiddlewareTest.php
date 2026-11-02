<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\JwtMiddleware;
use PHPUnit\Framework\TestCase;

final class JwtMiddlewareTest extends TestCase
{
    private const SECRET = 'phpunit-secret-value-0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        defined('BASEPATH') || define('BASEPATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
        defined('ROOTPATH') || define('ROOTPATH', BASEPATH);
        defined('API_JWT_SECRET') || define('API_JWT_SECRET', self::SECRET);
        require_once ROOTPATH . 'system/Core/Middleware.php';
        require_once ROOTPATH . 'system/Core/Request.php';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['AUTH_JWT_PAYLOAD']);
    }

    public function testValidTokenWithExpIsAccepted(): void
    {
        $this->authorize($this->token(['sub' => '1', 'exp' => time() + 60]));

        self::assertTrue((new JwtMiddleware())->handle());
    }

    public function testTokenWithoutExpIsRejected(): void
    {
        $this->authorize($this->token(['sub' => '1']));

        self::assertFalse((new JwtMiddleware())->handle());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->authorize($this->token(['sub' => '1', 'exp' => time() - 10]));

        self::assertFalse((new JwtMiddleware())->handle());
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $this->authorize($this->token(['sub' => '1', 'exp' => time() + 60]) . 'x');

        self::assertFalse((new JwtMiddleware())->handle());
    }

    public function testNoneAlgorithmIsRejected(): void
    {
        $header = $this->b64(json_encode(['alg' => 'none', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->b64(json_encode(['exp' => time() + 60], JSON_THROW_ON_ERROR));
        $this->authorize($header . '.' . $payload . '.');

        self::assertFalse((new JwtMiddleware())->handle());
    }

    private function authorize(string $token): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function token(array $claims): string
    {
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->b64(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = $this->b64(hash_hmac('sha256', $header . '.' . $payload, self::SECRET, true));

        return $header . '.' . $payload . '.' . $signature;
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
