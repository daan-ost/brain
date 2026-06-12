<?php

use App\Services\PasswordStrengthService;

describe('PasswordStrengthService', function () {
    describe('analyze method', function () {
        it('returns weak for short passwords with no variety', function () {
            $result = PasswordStrengthService::analyze('abc');

            expect($result['level'])->toBe('weak');
            expect($result['score'])->toBeLessThan(30);
            expect($result['color'])->toBe('red');
            expect($result['feedback'])->toContain('Zeer zwak');
        });

        it('returns weak for passwords with only lowercase letters', function () {
            $result = PasswordStrengthService::analyze('abcdefgh');

            expect($result['level'])->toBe('weak');
            expect($result['score'])->toBe(20); // 10 for length >=8, 10 for lowercase
        });

        it('returns fair for passwords with mixed case', function () {
            $result = PasswordStrengthService::analyze('Abcdefgh');

            expect($result['level'])->toBe('fair');
            expect($result['score'])->toBe(35); // 10 length, 10 lowercase, 15 uppercase
            expect($result['color'])->toBe('orange');
        });

        it('returns good for passwords with mixed case and numbers', function () {
            $result = PasswordStrengthService::analyze('Abcdefgh123');

            expect($result['level'])->toBe('good');
            expect($result['score'])->toBe(50); // 10 length (>=8), 10 lower, 15 upper, 15 numbers
            expect($result['color'])->toBe('yellow');
            expect($result['feedback'])->toBe('Goed wachtwoord.');
        });

        it('returns strong for passwords with all character types', function () {
            $result = PasswordStrengthService::analyze('Abcdefgh123!@#');

            expect($result['level'])->toBe('strong');
            expect($result['score'])->toBeGreaterThanOrEqual(70);
            expect($result['color'])->toBe('green');
            expect($result['feedback'])->toBe('Zeer sterk wachtwoord!');
        });

        it('awards points for longer passwords', function () {
            $short = PasswordStrengthService::analyze('Abc123!');
            $medium = PasswordStrengthService::analyze('Abcdefgh123!@#');
            $long = PasswordStrengthService::analyze('Abcdefgh123!@#$%^&*()');

            expect($medium['score'])->toBeGreaterThan($short['score']);
            expect($long['score'])->toBeGreaterThan($medium['score']);
        });

        it('correctly identifies all character types', function () {
            // Test lowercase detection
            $lowercase = PasswordStrengthService::analyze('abcdefgh');
            expect($lowercase['score'])->toBeGreaterThanOrEqual(10);

            // Test uppercase detection
            $uppercase = PasswordStrengthService::analyze('ABCDEFGH');
            expect($uppercase['score'])->toBeGreaterThanOrEqual(10);

            // Test numbers detection
            $numbers = PasswordStrengthService::analyze('12345678');
            expect($numbers['score'])->toBeGreaterThanOrEqual(10);

            // Test special characters detection
            $special = PasswordStrengthService::analyze('!@#$%^&*');
            expect($special['score'])->toBeGreaterThanOrEqual(10);
        });

        it('handles empty passwords', function () {
            $result = PasswordStrengthService::analyze('');

            expect($result['level'])->toBe('weak');
            expect($result['score'])->toBe(0);
        });

        it('handles very long passwords', function () {
            $veryLong = str_repeat('Abc123!@#', 10);
            $result = PasswordStrengthService::analyze($veryLong);

            expect($result['level'])->toBe('strong');
            expect($result['score'])->toBe(100); // Maximum score
        });

        it('awards correct points for length milestones', function () {
            // 8 chars: 10 points
            $result8 = PasswordStrengthService::analyze('a'.str_repeat('a', 7));
            expect($result8['score'])->toBeGreaterThanOrEqual(10);

            // 12 chars: 20 points
            $result12 = PasswordStrengthService::analyze('a'.str_repeat('a', 11));
            expect($result12['score'])->toBeGreaterThanOrEqual(20);

            // 16 chars: 30 points
            $result16 = PasswordStrengthService::analyze('a'.str_repeat('a', 15));
            expect($result16['score'])->toBeGreaterThanOrEqual(30);

            // 20 chars: 40 points
            $result20 = PasswordStrengthService::analyze('a'.str_repeat('a', 19));
            expect($result20['score'])->toBeGreaterThanOrEqual(40);
        });
    });

    describe('meetsMinimumStrength method', function () {
        it('returns true when password meets minimum strength', function () {
            $strongPassword = 'Abcdefgh123!@#';

            expect(PasswordStrengthService::meetsMinimumStrength($strongPassword, 'weak'))->toBeTrue();
            expect(PasswordStrengthService::meetsMinimumStrength($strongPassword, 'fair'))->toBeTrue();
            expect(PasswordStrengthService::meetsMinimumStrength($strongPassword, 'good'))->toBeTrue();
            expect(PasswordStrengthService::meetsMinimumStrength($strongPassword, 'strong'))->toBeTrue();
        });

        it('returns false when password does not meet minimum strength', function () {
            $weakPassword = 'abc';

            expect(PasswordStrengthService::meetsMinimumStrength($weakPassword, 'fair'))->toBeFalse();
            expect(PasswordStrengthService::meetsMinimumStrength($weakPassword, 'good'))->toBeFalse();
            expect(PasswordStrengthService::meetsMinimumStrength($weakPassword, 'strong'))->toBeFalse();
        });

        it('uses good as default minimum level', function () {
            $fairPassword = 'Abcdefgh'; // This should be fair level
            $goodPassword = 'Abcdefgh123'; // This should be good level

            expect(PasswordStrengthService::meetsMinimumStrength($fairPassword))->toBeFalse();
            expect(PasswordStrengthService::meetsMinimumStrength($goodPassword))->toBeTrue();
        });

        it('correctly compares strength levels', function () {
            $weak = 'abc';
            $fair = 'Abcdefgh';
            $good = 'Abcdefgh123';
            $strong = 'Abcdefgh123!@#';

            // Weak password
            expect(PasswordStrengthService::meetsMinimumStrength($weak, 'weak'))->toBeTrue();
            expect(PasswordStrengthService::meetsMinimumStrength($weak, 'fair'))->toBeFalse();

            // Fair password
            expect(PasswordStrengthService::meetsMinimumStrength($fair, 'weak'))->toBeTrue();
            expect(PasswordStrengthService::meetsMinimumStrength($fair, 'fair'))->toBeTrue();
            expect(PasswordStrengthService::meetsMinimumStrength($fair, 'good'))->toBeFalse();

            // Good password
            expect(PasswordStrengthService::meetsMinimumStrength($good, 'good'))->toBeTrue();
            expect(PasswordStrengthService::meetsMinimumStrength($good, 'strong'))->toBeFalse();

            // Strong password
            expect(PasswordStrengthService::meetsMinimumStrength($strong, 'strong'))->toBeTrue();
        });
    });

    describe('getColorClass method', function () {
        it('returns correct Tailwind CSS classes for each level', function () {
            expect(PasswordStrengthService::getColorClass('weak'))->toBe('bg-red-500');
            expect(PasswordStrengthService::getColorClass('fair'))->toBe('bg-orange-500');
            expect(PasswordStrengthService::getColorClass('good'))->toBe('bg-yellow-500');
            expect(PasswordStrengthService::getColorClass('strong'))->toBe('bg-green-500');
        });

        it('returns default class for unknown level', function () {
            expect(PasswordStrengthService::getColorClass('unknown'))->toBe('bg-gray-300');
            expect(PasswordStrengthService::getColorClass(''))->toBe('bg-gray-300');
        });
    });

    describe('edge cases', function () {
        it('handles unicode characters', function () {
            $unicode = 'Пароль123!'; // Cyrillic characters
            $result = PasswordStrengthService::analyze($unicode);

            expect($result)->toBeArray();
            expect($result['level'])->toBeString();
        });

        it('handles only special characters', function () {
            $special = '!@#$%^&*()_+';
            $result = PasswordStrengthService::analyze($special);

            expect($result)->toBeArray();
            expect($result['score'])->toBeGreaterThan(0);
        });

        it('handles mixed emoji and text', function () {
            $emoji = 'Password123!😀';
            $result = PasswordStrengthService::analyze($emoji);

            expect($result)->toBeArray();
            expect($result['level'])->toBeString();
        });

        it('returns consistent results for same password', function () {
            $password = 'TestPassword123!';

            $result1 = PasswordStrengthService::analyze($password);
            $result2 = PasswordStrengthService::analyze($password);

            expect($result1)->toEqual($result2);
        });
    });
});
