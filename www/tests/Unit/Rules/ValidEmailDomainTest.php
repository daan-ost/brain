<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidEmailDomain;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ValidEmailDomainTest extends TestCase
{
    private function validate(string $email): array
    {
        $validator = Validator::make(
            ['email' => $email],
            ['email' => new ValidEmailDomain]
        );

        return [
            'passes' => $validator->passes(),
            'errors' => $validator->errors()->get('email'),
        ];
    }

    #[Test]
    public function it_passes_valid_emails_with_known_domains(): void
    {
        $validEmails = [
            'johnsmith@gmail.com',
            'jane.doe@outlook.com',
            'mypersonal@hotmail.com',
            'realuser@yahoo.com',
            'myname@icloud.com',
            'personal@protonmail.com',
        ];

        foreach ($validEmails as $email) {
            $result = $this->validate($email);
            $this->assertTrue($result['passes'], "Email '$email' should be valid");
        }
    }

    #[Test]
    public function it_rejects_blocked_usernames(): void
    {
        $blockedUsernames = [
            'test@gmail.com',
            'noreply@outlook.com',
            'admin@yahoo.com',
            'info@hotmail.com',
            'support@icloud.com',
            'asdf@gmail.com',
            'anonymous@protonmail.com',
        ];

        foreach ($blockedUsernames as $email) {
            $result = $this->validate($email);
            $this->assertFalse($result['passes'], "Email '$email' should be blocked");
            $this->assertNotEmpty($result['errors']);
        }
    }

    #[Test]
    public function it_rejects_blocked_domains(): void
    {
        $blockedDomains = [
            'john@mailinator.com',
            'jane@tempmail.com',
            'user@guerrillamail.com',
            'test123@yopmail.com',
            'hello@10minutemail.com',
            'contact@example.com',
            'info@trashmail.com',
        ];

        foreach ($blockedDomains as $email) {
            $result = $this->validate($email);
            $this->assertFalse($result['passes'], "Email '$email' should be blocked");
            $this->assertNotEmpty($result['errors']);
        }
    }

    #[Test]
    public function it_suggests_correction_for_typos(): void
    {
        $typoEmails = [
            'johnsmith@gamil.com' => 'johnsmith@gmail.com',
            'janedoe@gnail.com' => 'janedoe@gmail.com',
            'myname@gmail.con' => 'myname@gmail.com',
            'personal@hotmail.con' => 'personal@hotmail.com',
        ];

        foreach ($typoEmails as $typoEmail => $correctedEmail) {
            $result = $this->validate($typoEmail);
            $this->assertFalse($result['passes'], "Email '$typoEmail' should fail");
            $this->assertNotEmpty($result['errors']);
            $this->assertStringContainsString($correctedEmail, $result['errors'][0] ?? '');
        }
    }

    #[Test]
    public function it_rejects_gmail_usernames_shorter_than_6_characters(): void
    {
        $shortGmailUsernames = [
            'ab@gmail.com',
            'abc@gmail.com',
            'abcd@gmail.com',
            'abcde@gmail.com',
        ];

        foreach ($shortGmailUsernames as $email) {
            $result = $this->validate($email);
            $this->assertFalse($result['passes'], "Email '$email' should be rejected (too short)");
            $this->assertNotEmpty($result['errors']);
        }
    }

    #[Test]
    public function it_allows_gmail_usernames_with_6_or_more_characters(): void
    {
        $validGmailUsernames = [
            'abcdef@gmail.com',
            'abcdefg@gmail.com',
            'longusername@gmail.com',
        ];

        foreach ($validGmailUsernames as $email) {
            $result = $this->validate($email);
            $this->assertTrue($result['passes'], "Email '$email' should be valid");
        }
    }

    #[Test]
    public function it_handles_empty_values_gracefully(): void
    {
        $result = $this->validate('');
        $this->assertTrue($result['passes']);
    }

    #[Test]
    public function it_handles_invalid_email_format_gracefully(): void
    {
        // These formats don't have exactly one @ sign, so the rule returns early
        $invalidFormats = [
            'notanemail',
            'noatsign.com',
            'multiple@@signs.com',
        ];

        foreach ($invalidFormats as $email) {
            $result = $this->validate($email);
            $this->assertTrue($result['passes'], "Invalid format '$email' should pass (let other validators handle format)");
        }
    }

    #[Test]
    public function it_is_case_insensitive_for_domains(): void
    {
        $result = $this->validate('john@GAMIL.COM');
        $this->assertFalse($result['passes'], 'Typo detection should be case-insensitive');

        $result = $this->validate('john@MAILINATOR.COM');
        $this->assertFalse($result['passes'], 'Blocked domain detection should be case-insensitive');
    }

    #[Test]
    public function it_is_case_insensitive_for_usernames(): void
    {
        $result = $this->validate('TEST@gmail.com');
        $this->assertFalse($result['passes'], 'Blocked username detection should be case-insensitive');

        $result = $this->validate('NOREPLY@outlook.com');
        $this->assertFalse($result['passes'], 'Blocked username detection should be case-insensitive');
    }

    #[Test]
    #[DataProvider('blockedUsernameProvider')]
    public function it_blocks_all_specified_usernames(string $username): void
    {
        $email = $username.'@gmail.com';
        $result = $this->validate($email);
        $this->assertFalse($result['passes'], "Username '$username' should be blocked");
    }

    public static function blockedUsernameProvider(): array
    {
        return [
            ['no'],
            ['noreply'],
            ['no-reply'],
            ['test'],
            ['testing'],
            ['admin'],
            ['administrator'],
            ['info'],
            ['support'],
            ['hello'],
            ['contact'],
            ['sales'],
            ['asdf'],
            ['qwerty'],
            ['aaa'],
            ['bbb'],
            ['xxx'],
            ['zzz'],
            ['123'],
            ['1234'],
            ['12345'],
            ['abc'],
            ['abcd'],
            ['user'],
            ['demo'],
            ['sample'],
            ['example'],
            ['fake'],
            ['temp'],
            ['temporary'],
            ['null'],
            ['void'],
            ['none'],
            ['nobody'],
            ['anonymous'],
            ['anon'],
        ];
    }

    #[Test]
    #[DataProvider('blockedDomainProvider')]
    public function it_blocks_all_specified_domains(string $domain): void
    {
        $email = 'validuser@'.$domain;
        $result = $this->validate($email);
        $this->assertFalse($result['passes'], "Domain '$domain' should be blocked");
    }

    public static function blockedDomainProvider(): array
    {
        return [
            ['noreply.com'],
            ['example.com'],
            ['example.org'],
            ['example.net'],
            ['test.com'],
            ['test.org'],
            ['localhost'],
            ['localht.com'],
            ['invalid.com'],
            ['fake.com'],
            ['mailinator.com'],
            ['guerrillamail.com'],
            ['guerrillamail.org'],
            ['tempmail.com'],
            ['tempmail.org'],
            ['temp-mail.org'],
            ['throwaway.email'],
            ['throwawaymail.com'],
            ['fakeinbox.com'],
            ['sharklasers.com'],
            ['grr.la'],
            ['guerrillamail.info'],
            ['pokemail.net'],
            ['spam4.me'],
            ['yopmail.com'],
            ['yopmail.fr'],
            ['cool.fr.nf'],
            ['jetable.fr.nf'],
            ['10minutemail.com'],
            ['10minutemail.net'],
            ['10minmail.com'],
            ['tempinbox.com'],
            ['tempr.email'],
            ['discard.email'],
            ['discardmail.com'],
            ['disposablemail.com'],
            ['disposable.com'],
            ['getairmail.com'],
            ['getnada.com'],
            ['mailnesia.com'],
            ['maildrop.cc'],
            ['mintemail.com'],
            ['mohmal.com'],
            ['spamgourmet.com'],
            ['trashmail.com'],
            ['trashmail.net'],
            ['wegwerfmail.de'],
            ['wegwerfmail.net'],
            ['emailondeck.com'],
            ['anonymbox.com'],
            ['tempail.com'],
            ['burnermail.io'],
            ['mailcatch.com'],
            ['inboxalias.com'],
        ];
    }
}
