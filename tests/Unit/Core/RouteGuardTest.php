<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use System\Route;

final class RouteGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        defined('BASEPATH') || define('BASEPATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
        defined('ROOTPATH') || define('ROOTPATH', BASEPATH);
        require_once ROOTPATH . 'system/Core/Middleware.php';
        require_once ROOTPATH . 'system/Core/RouteGroup.php';
        require_once ROOTPATH . 'system/Core/Route.php';
    }

    protected function setUp(): void
    {
        Route::flush();
    }

    protected function tearDown(): void
    {
        Route::flush();
    }

    public function testAllowedMethodsListsOnlyRegisteredMethodsForPath(): void
    {
        Route::post('/admin/save', 'Admin@save');
        Route::get('/admin/save', 'Admin@form');
        Route::get('/users/{id}', 'Users@show');

        self::assertEqualsCanonicalizing(['POST', 'GET'], Route::allowedMethods('/admin/save'));
        self::assertSame(['GET'], Route::allowedMethods('/users/42'));
        self::assertSame([], Route::allowedMethods('/unknown'));
    }

    public function testHasActionIsCaseInsensitiveAndOnlyMatchesRegisteredActions(): void
    {
        Route::post('/admin/save', 'Admin@save')->middleware(['Auth', 'Csrf']);

        self::assertTrue(Route::hasAction('Admin', 'save'));
        self::assertTrue(Route::hasAction('admin', 'SAVE'));
        self::assertFalse(Route::hasAction('Admin', 'delete'));
        self::assertFalse(Route::hasAction('Other', 'save'));
    }

    public function testMatchStillReturnsMiddlewareForRegisteredMethod(): void
    {
        Route::post('/admin/save', 'Admin@save')->middleware(['Auth', 'Csrf']);

        $target = Route::match('POST', '/admin/save');

        self::assertNotNull($target);
        self::assertSame(['Auth', 'Csrf'], $target[1]);
        self::assertNull(Route::match('GET', '/admin/save'));
    }
}
