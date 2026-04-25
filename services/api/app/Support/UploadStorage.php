<?php

namespace App\Support;

class UploadStorage
{
    public static function finalDisk(): string
    {
        return (string) config('upload.storage.final_disk', 'upload_final');
    }

    public static function quarantineDisk(): string
    {
        return (string) config('upload.storage.quarantine_disk', 'upload_quarantine');
    }

    public static function finalPrefix(): string
    {
        return self::normalizePrefix((string) config('upload.storage.final_prefix', 'uploads'));
    }

    public static function quarantinePrefix(): string
    {
        return self::normalizePrefix((string) config('upload.storage.quarantine_prefix', 'uploads'));
    }

    public static function finalKey(string $uploadId, string $filename): string
    {
        return self::buildKey(self::finalPrefix(), $uploadId, $filename);
    }

    public static function quarantineKey(string $uploadId, string $filename): string
    {
        return self::buildKey(self::quarantinePrefix(), $uploadId, $filename);
    }

    public static function quarantineMarkerKey(string $uploadId): string
    {
        return self::buildKey(self::quarantinePrefix(), $uploadId, '.published');
    }

    public static function formatPath(string $disk, string $key): string
    {
        $config = (array) config("filesystems.disks.{$disk}", []);
        $driver = (string) ($config['driver'] ?? '');

        if ($driver === 's3') {
            $bucket = (string) ($config['bucket'] ?? '');
            $bucketPart = $bucket !== '' ? "{$bucket}/" : '';
            return "s3://{$bucketPart}{$key}";
        }

        if ($driver === 'local') {
            $root = (string) ($config['root'] ?? '');
            $root = rtrim($root, "/\\");
            return $root !== '' ? "{$root}/{$key}" : $key;
        }

        return $key;
    }

    private static function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        return trim($prefix, '/');
    }

    private static function buildKey(string $prefix, string $uploadId, string $filename): string
    {
        $base = $prefix === '' ? '' : "{$prefix}/";
        return "{$base}{$uploadId}/{$filename}";
    }
}
