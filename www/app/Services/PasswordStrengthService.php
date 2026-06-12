<?php

namespace App\Services;

class PasswordStrengthService
{
    /**
     * Calculate password strength and return score with details
     *
     * @param  string  $password
     * @return array{score: int, level: string, feedback: string, color: string}
     */
    public static function analyze(string $password): array
    {
        $score = 0;
        $length = strlen($password);

        // Length scoring (0-40 points)
        if ($length >= 8) {
            $score += 10;
        }
        if ($length >= 12) {
            $score += 10;
        }
        if ($length >= 16) {
            $score += 10;
        }
        if ($length >= 20) {
            $score += 10;
        }

        // Character variety scoring (0-60 points)
        $hasLowercase = preg_match('/[a-z]/', $password);
        $hasUppercase = preg_match('/[A-Z]/', $password);
        $hasNumbers = preg_match('/[0-9]/', $password);
        $hasSpecialChars = preg_match('/[^a-zA-Z0-9]/', $password);

        if ($hasLowercase) {
            $score += 10;
        }
        if ($hasUppercase) {
            $score += 15;
        }
        if ($hasNumbers) {
            $score += 15;
        }
        if ($hasSpecialChars) {
            $score += 20;
        }

        // Determine level, feedback, and color based on score
        if ($score < 30) {
            return [
                'score' => $score,
                'level' => 'weak',
                'feedback' => 'Zeer zwak. Voeg meer tekens toe.',
                'color' => 'red',
            ];
        } elseif ($score < 50) {
            return [
                'score' => $score,
                'level' => 'fair',
                'feedback' => 'Redelijk, maar kan beter. Probeer hoofdletters, cijfers en symbolen te gebruiken.',
                'color' => 'orange',
            ];
        } elseif ($score < 70) {
            return [
                'score' => $score,
                'level' => 'good',
                'feedback' => 'Goed wachtwoord.',
                'color' => 'yellow',
            ];
        } else {
            return [
                'score' => $score,
                'level' => 'strong',
                'feedback' => 'Zeer sterk wachtwoord!',
                'color' => 'green',
            ];
        }
    }

    /**
     * Check if password meets minimum strength requirements
     *
     * @param  string  $password
     * @param  string  $minimumLevel  Minimum required level (weak, fair, good, strong)
     * @return bool
     */
    public static function meetsMinimumStrength(string $password, string $minimumLevel = 'good'): bool
    {
        $analysis = self::analyze($password);
        $levels = ['weak', 'fair', 'good', 'strong'];

        $currentLevelIndex = array_search($analysis['level'], $levels);
        $requiredLevelIndex = array_search($minimumLevel, $levels);

        return $currentLevelIndex >= $requiredLevelIndex;
    }

    /**
     * Get color class for Tailwind CSS based on strength level
     *
     * @param  string  $level
     * @return string
     */
    public static function getColorClass(string $level): string
    {
        return match ($level) {
            'weak' => 'bg-red-500',
            'fair' => 'bg-orange-500',
            'good' => 'bg-yellow-500',
            'strong' => 'bg-green-500',
            default => 'bg-gray-300',
        };
    }
}
