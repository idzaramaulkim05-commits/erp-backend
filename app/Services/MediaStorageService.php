<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorageService
{
    /**
     * Store an uploaded file directly into server public storage.
     */
    public static function storeUploadedFile(UploadedFile $file, string $folder = 'general', ?string $prefix = null): string
    {
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $prefix = $prefix ? Str::slug($prefix, '_') . '_' : '';
        $filename = $prefix . date('Ymd_His') . '_' . Str::random(8) . '.' . strtolower($extension);

        $path = $file->storeAs($folder, $filename, 'public');

        return 'storage/' . ltrim($path, '/');
    }

    /**
     * Store raw binary content or base64 data to local server disk.
     */
    public static function storeRawContent(string $content, string $folder = 'general', ?string $prefix = null, string $extension = 'jpg'): string
    {
        $prefix = $prefix ? Str::slug($prefix, '_') . '_' : '';
        $filename = $prefix . date('Ymd_His') . '_' . Str::random(8) . '.' . strtolower($extension);
        $relativePath = trim($folder, '/') . '/' . $filename;

        Storage::disk('public')->put($relativePath, $content);

        return 'storage/' . $relativePath;
    }

    /**
     * Download any remote image (especially Google Drive, Google Docs, or external URLs)
     * and save it directly to the local server disk.
     */
    public static function downloadAndStoreFromUrl(?string $url, string $folder = 'downloads', ?string $prefix = null): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);

        // 1. If already local storage path, normalize and return
        if (str_starts_with($url, 'storage/') || str_starts_with($url, 'tickets/') || str_starts_with($url, 'datasheet/') || str_starts_with($url, 'payments/') || str_starts_with($url, 'warehouse/')) {
            return str_starts_with($url, 'storage/') ? $url : 'storage/' . $url;
        }

        // 2. Check if it is a remote URL
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return $url;
        }

        // Resolve Google Drive direct download URL candidates
        $downloadUrls = self::buildDownloadUrls($url);
        $binary = null;
        $detectedExtension = 'jpg';

        foreach ($downloadUrls as $candidateUrl) {
            try {
                $response = Http::timeout(20)->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])->get($candidateUrl);

                if ($response->successful() && strlen($response->body()) > 200) {
                    $contentType = $response->header('Content-Type') ?? '';
                    if (str_contains($contentType, 'image') || str_contains($contentType, 'octet-stream') || str_contains($candidateUrl, 'googleusercontent')) {
                        $binary = $response->body();
                        if (str_contains($contentType, 'png')) {
                            $detectedExtension = 'png';
                        } elseif (str_contains($contentType, 'webp')) {
                            $detectedExtension = 'webp';
                        } elseif (str_contains($contentType, 'pdf')) {
                            $detectedExtension = 'pdf';
                        }
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("MediaStorageService download attempt failed for {$candidateUrl}: " . $e->getMessage());
            }
        }

        if ($binary !== null) {
            $prefix = $prefix ? Str::slug($prefix, '_') . '_' : '';
            $filename = $prefix . date('Ymd_His') . '_' . Str::random(8) . '.' . $detectedExtension;
            $relativePath = trim($folder, '/') . '/' . $filename;

            Storage::disk('public')->put($relativePath, $binary);

            return 'storage/' . $relativePath;
        }

        return $url;
    }

    /**
     * Build viable direct download URLs from various Google Drive & web link formats.
     */
    protected static function buildDownloadUrls(string $url): array
    {
        $urls = [];

        // Check if it's a Google Drive link
        if (str_contains($url, 'drive.google.com') || str_contains($url, 'docs.google.com') || str_contains($url, 'googleusercontent.com')) {
            $fileId = null;

            if (preg_match('/\/file\/d\/([a-zA-Z0-9_-]+)/i', $url, $matches)) {
                $fileId = $matches[1];
            } elseif (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/i', $url, $matches)) {
                $fileId = $matches[1];
            } elseif (preg_match('/\/d\/([a-zA-Z0-9_-]+)/i', $url, $matches)) {
                $fileId = $matches[1];
            }

            if ($fileId) {
                // Highest reliability: Google User Content CDN thumbnail (w2048 high-res)
                $urls[] = "https://lh3.googleusercontent.com/d/{$fileId}=w2048";
                $urls[] = "https://lh3.googleusercontent.com/d/{$fileId}";
                $urls[] = "https://drive.google.com/uc?export=download&id={$fileId}";
                $urls[] = "https://docs.google.com/uc?export=download&id={$fileId}";
            }
        }

        $urls[] = $url;

        return $urls;
    }

    /**
     * Resolve any storage path or URL to an absolute browser-accessible URL.
     */
    public static function resolveUrl(?string $pathOrUrl): string
    {
        if (empty($pathOrUrl)) {
            return '';
        }

        $pathOrUrl = trim($pathOrUrl);

        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            if (str_contains($pathOrUrl, 'drive.google.com') || str_contains($pathOrUrl, 'docs.google.com')) {
                if (preg_match('/\/file\/d\/([a-zA-Z0-9_-]+)/i', $pathOrUrl, $m)) {
                    return "https://lh3.googleusercontent.com/d/{$m[1]}";
                }
                if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/i', $pathOrUrl, $m)) {
                    return "https://lh3.googleusercontent.com/d/{$m[1]}";
                }
            }
            return $pathOrUrl;
        }

        $clean = ltrim(str_replace('storage/', '', $pathOrUrl), '/\\');

        return asset('storage/' . $clean);
    }
}
