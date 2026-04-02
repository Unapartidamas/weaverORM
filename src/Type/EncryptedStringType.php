<?php

declare(strict_types=1);

namespace Weaver\ORM\Type;

use Weaver\ORM\DBAL\Platform;
use RuntimeException;

final class EncryptedStringType extends WeaverType
{
    private const CIPHER    = 'AES-256-CBC';
    private const DEV_KEY   = 'weaver-orm-dev-key-32-chars-pad!!';
    private const IV_LENGTH = 16;

    public function getName(): string
    {
        return 'encrypted_string';
    }

    public function getSQLDeclaration(array $column, Platform $platform): string
    {
        return 'TEXT';
    }

    public function convertToDatabaseValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        $key = $this->resolveKey();
        $iv  = random_bytes(self::IV_LENGTH);

        $encrypted = openssl_encrypt((string) $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new RuntimeException('EncryptedStringType: encryption failed.');
        }

        return base64_encode($iv . $encrypted);
    }

    public function convertToPHPValue(mixed $value, Platform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        $key     = $this->resolveKey();
        $decoded = base64_decode((string) $value, strict: true);

        if ($decoded === false || strlen($decoded) <= self::IV_LENGTH) {
            throw new RuntimeException('EncryptedStringType: invalid ciphertext.');
        }

        $iv         = substr($decoded, 0, self::IV_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH);

        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new RuntimeException('EncryptedStringType: decryption failed.');
        }

        return $decrypted;
    }

    private function resolveKey(): string
    {
        $envKey = getenv('WEAVER_ENCRYPT_KEY');

        $raw = ($envKey !== false && $envKey !== '') ? $envKey : self::DEV_KEY;

        return substr(hash('sha256', $raw, true), 0, 32);
    }
}
