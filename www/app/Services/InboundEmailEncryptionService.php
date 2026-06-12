<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class InboundEmailEncryptionService
{
    /**
     * Encrypt a value
     */
    public function encrypt($value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::encryptString($value);
        } catch (\Exception $e) {
            \Log::error('Failed to encrypt inbound email data', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Decrypt a value
     */
    public function decrypt(?string $value)
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            \Log::error('Failed to decrypt inbound email data', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Encrypt an array of data
     */
    public function encryptArray(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->encrypt($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Decrypt an array of data
     */
    public function decryptArray(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->decrypt($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Encrypt file content and save to storage
     */
    public function encryptFile(string $content, string $path): bool
    {
        try {
            $encrypted = $this->encrypt($content);

            return \Storage::put($path, $encrypted);
        } catch (\Exception $e) {
            \Log::error('Failed to encrypt and save file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Decrypt file content from storage
     */
    public function decryptFile(string $path): ?string
    {
        try {
            $encrypted = \Storage::get($path);

            return $this->decrypt($encrypted);
        } catch (\Exception $e) {
            \Log::error('Failed to decrypt file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the list of fields that should be encrypted for InboundEmail
     */
    public static function getInboundEmailEncryptedFields(): array
    {
        return [
            'from_email',
            'from_name',
            'subject',
            'body_text',
            'body_html',
            'headers',
        ];
    }

    /**
     * Get the list of fields that should be encrypted for InboundEmailAttachment
     */
    public static function getAttachmentEncryptedFields(): array
    {
        return [
            'original_filename',
            'file_path',
        ];
    }
}
