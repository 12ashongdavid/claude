<?php
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testSanitizeEscapesHtmlAndTrims(): void
    {
        $this->assertSame('&lt;script&gt;', sanitize(' <script> '));
    }

    public function testFormatCurrencyUsesGhanaCediSymbolAndTwoDecimals(): void
    {
        $this->assertSame('GH₵ 1,500.00', formatCurrency(1500));
        $this->assertSame('GH₵ 0.00', formatCurrency(0));
    }

    public function testValidatePhoneRequiresExactlyTenDigits(): void
    {
        $this->assertTrue(validatePhone('0245551234'));
        $this->assertFalse(validatePhone('024555123'));   // 9 digits
        $this->assertFalse(validatePhone('02455512345')); // 11 digits
        $this->assertFalse(validatePhone('024555123a'));  // non-digit
    }

    public function testValidateEmailDetailedAcceptsAWhitelistedProvider(): void
    {
        $result = validateEmailDetailed('someone@gmail.com');
        $this->assertTrue($result['valid']);
        $this->assertSame('', $result['message']);
    }

    public function testValidateEmailDetailedRejectsAnUnknownProvider(): void
    {
        $result = validateEmailDetailed('someone@totally-random-domain.xyz');
        $this->assertFalse($result['valid']);
        $this->assertNotSame('', $result['message']);
    }

    public function testValidateEmailDetailedCanSkipTheWhitelist(): void
    {
        $result = validateEmailDetailed('someone@totally-random-domain.xyz', true);
        $this->assertTrue($result['valid']);
    }

    public function testValidateEmailDetailedTreatsEmptyAsValid(): void
    {
        // Whether email is required at all is the caller's decision, not this function's.
        $this->assertTrue(validateEmailDetailed('')['valid']);
    }

    public function testValidateEmailDetailedRejectsMalformedAddresses(): void
    {
        $this->assertFalse(validateEmailDetailed('not-an-email')['valid']);
        $this->assertFalse(validateEmailDetailed('double..dot@gmail.com')['valid']);
    }

    public function testValidatePasswordListsEveryMissingRequirement(): void
    {
        $this->assertSame([], validatePassword('Str0ng!Pass'));
        $this->assertContains('at least 8 characters', validatePassword('S1!'));
        $this->assertContains('an uppercase letter', validatePassword('weak1!weak'));
        $this->assertContains('a number', validatePassword('NoNumbers!'));
        $this->assertContains('a special character', validatePassword('NoSpecial1'));
    }

    public function testGetPasswordStrengthScoresZeroToFour(): void
    {
        $this->assertSame(0, getPasswordStrength(''));
        $this->assertSame(4, getPasswordStrength('Str0ng!Pass'));
    }

    public function testValidateStartDateAllowsUpToOneMonthInThePast(): void
    {
        $this->assertTrue(validateStartDate(''));
        $this->assertTrue(validateStartDate(date('Y-m-d')));
        $this->assertTrue(validateStartDate(date('Y-m-d', strtotime('-2 weeks'))));
        $this->assertFalse(validateStartDate(date('Y-m-d', strtotime('-2 months'))));
    }

    public function testValidateAgeRejectsUnder18(): void
    {
        $this->assertTrue(validateAge(date('Y-m-d', strtotime('-18 years -1 day'))));
        $this->assertFalse(validateAge(date('Y-m-d', strtotime('-17 years'))));
        $this->assertFalse(validateAge(''));
    }

    public function testYmDiffMonthsCountsWholeMonthsBetweenTwoYearMonths(): void
    {
        $this->assertSame(0, ymDiffMonths('2026-01', '2026-01'));
        $this->assertSame(2, ymDiffMonths('2026-01', '2026-03'));
        $this->assertSame(12, ymDiffMonths('2025-06', '2026-06'));
    }

    public function testIsMobileUserAgentDetectsPhonesAndTabletsNotDesktops(): void
    {
        $original = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';
        $this->assertTrue(isMobileUserAgent());

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14)';
        $this->assertTrue(isMobileUserAgent());

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0';
        $this->assertFalse(isMobileUserAgent());

        unset($_SERVER['HTTP_USER_AGENT']);
        $this->assertFalse(isMobileUserAgent());

        if ($original !== null) {
            $_SERVER['HTTP_USER_AGENT'] = $original;
        }
    }

    public function testSendSmsRejectsInvalidInputWithoutContactingTheProvider(): void
    {
        // Guards against ever making a real network call to mNotify from the
        // test suite — these all fail validation before any HTTP request.
        $this->assertFalse(sendSMS('12345', 'too short a phone number'));
        $this->assertFalse(sendSMS('0245551234', ''));
    }
}
