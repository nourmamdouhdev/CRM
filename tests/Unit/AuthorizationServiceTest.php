<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthorizationService;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCanReturnsFalseWhenNoUser(): void
    {
        $service = new AuthorizationService();
        self::assertFalse($service->can(['admin']));
    }

    public function testCanReturnsTrueWhenRoleMatches(): void
    {
        $_SESSION['user'] = ['role' => 'manager'];
        $service = new AuthorizationService();
        self::assertTrue($service->can(['admin', 'manager']));
    }
}

