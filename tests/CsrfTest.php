<?php
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['csrf_token']);
    }

    public function testGenerateCsrfTokenIsStableAcrossCallsInTheSameSession(): void
    {
        $first = generateCSRFToken();
        $second = generateCSRFToken();
        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first)); // bin2hex(random_bytes(32))
    }

    public function testValidateCsrfTokenAcceptsTheRealTokenAndRejectsEverythingElse(): void
    {
        $token = generateCSRFToken();
        $this->assertTrue(validateCSRFToken($token));
        $this->assertFalse(validateCSRFToken('wrong-token'));
        $this->assertFalse(validateCSRFToken(''));
    }

    public function testValidateCsrfTokenFailsClosedWithNoSessionToken(): void
    {
        unset($_SESSION['csrf_token']);
        $this->assertFalse(validateCSRFToken('anything'));
    }
}
