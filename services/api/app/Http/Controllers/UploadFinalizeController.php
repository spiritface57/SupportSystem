<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UploadFinalizeController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'upload_id'   => 'required|string',
            'filename'    => 'required|string|max:255',
            'total_bytes' => 'required|integer|min:1',
        ]);

        $uploadId   = $data['upload_id'];
        $filename   = $data['filename'];
        $totalBytes = (int) $data['total_bytes'];

        /* -------------------------------
         * Locate chunks
         * ------------------------------- */
        $chunksDir = storage_path("app/uploads/{$uploadId}");
        if (!is_dir($chunksDir)) {
            return response()->json([
                'error' => 'chunks_not_found',
            ], 400);
        }

        /* -------------------------------
         * Assemble into TEMP (fail-fast)
         * ------------------------------- */
        $tempDir = storage_path("app/tmp/{$uploadId}");
        File::ensureDirectoryExists($tempDir);

        $tempAssembledPath = "{$tempDir}/assembled.bin";
        $out = fopen($tempAssembledPath, 'wb');

        if ($out === false) {
            return response()->json([
                'error' => 'temp_open_failed',
            ], 500);
        }

        $written = 0;

        $chunks = collect(File::files($chunksDir))
            ->sortBy(fn($f) => (int) pathinfo($f->getFilename(), PATHINFO_FILENAME));

        foreach ($chunks as $chunk) {
            $path = $chunk->getPathname();

            if (!is_readable($path)) {
                fclose($out);
                File::delete($tempAssembledPath);

                return response()->json([
                    'error' => 'chunk_unreadable',
                    'chunk' => basename($path),
                ], 400);
            }

            $in = @fopen($path, 'rb');
            if ($in === false) {
                fclose($out);
                File::delete($tempAssembledPath);

                return response()->json([
                    'error' => 'chunk_open_failed',
                    'chunk' => basename($path),
                ], 400);
            }

            while (!feof($in)) {
                $buf = fread($in, 8192);
                if ($buf === false) {
                    fclose($in);
                    fclose($out);
                    File::delete($tempAssembledPath);

                    return response()->json([
                        'error' => 'chunk_read_failed',
                        'chunk' => basename($path),
                    ], 400);
                }

                fwrite($out, $buf);
                $written += strlen($buf);
            }

            fclose($in);
        }

        fclose($out);

        if ($written !== $totalBytes) {
            File::delete($tempAssembledPath);

            return response()->json([
                'error'   => 'size_mismatch',
                'written' => $written,
            ], 400);
        }

        /* -------------------------------
         * Scan gate
         * ------------------------------- */
        $hash = hash_file('sha256', $tempAssembledPath);

        $stream = fopen($tempAssembledPath, 'rb');

        $scannerResponse = Http::timeout(config('scanner.timeout'))
            ->withHeaders([
                'Content-Type' => 'application/octet-stream',
            ])
            ->send('POST', config('scanner.base_url') . '/scan', [
                'body' => $stream,
            ]);

        fclose($stream);

        $statusCode = $scannerResponse->status();
        $body = $scannerResponse->json();

        /* --- Case A: scanner unavailable --- */
        if ($scannerResponse->failed()) {
            File::delete($tempAssembledPath);

            Log::error('scanner_unavailable', [
                'upload_id'   => $uploadId,
                'http_status' => $statusCode,
            ]);

            return response()->json([
                'error'  => 'scan_failed',
                'reason' => 'scanner_unavailable',
            ], 502);
        }

        /* --- Case C: malformed response --- */
        if (!is_array($body) || !isset($body['status'])) {
            File::delete($tempAssembledPath);

            Log::error('scanner_invalid_response', [
                'upload_id' => $uploadId,
                'body'      => $body,
            ]);

            return response()->json([
                'error'  => 'scan_failed',
                'reason' => 'scanner_invalid_response',
            ], 502);
        }

        /* --- Case B: infected --- */
        if ($body['status'] === 'infected') {
            Log::channel('infected')->warning('infected_upload', [
                'upload_id' => $uploadId,
                'filename'  => $filename,
                'bytes'     => $written,
                'sha256'    => $hash,
                'signature' => $body['signature'] ?? null,
                'ip'        => $request->ip(),
            ]);

            File::delete($tempAssembledPath);
            File::deleteDirectory($chunksDir);

            return response()->json([
                'error'  => 'scan_failed',
                'reason' => 'infected',
            ], 400);
        }

        /* --- Case D: clean --- */
        if ($body['status'] !== 'clean') {
            File::delete($tempAssembledPath);

            Log::error('scanner_unknown_state', [
                'upload_id' => $uploadId,
                'body'      => $body,
            ]);

            return response()->json([
                'error'  => 'scan_failed',
                'reason' => 'unknown_scanner_state',
            ], 502);
        }

        /* -------------------------------
         * Commit to FINAL
         * ------------------------------- */
        $finalDir = storage_path("app/final/uploads/{$uploadId}");
        File::ensureDirectoryExists($finalDir);

        $finalPath = "{$finalDir}/{$filename}";
        File::move($tempAssembledPath, $finalPath);

        /* -------------------------------
         * Cleanup
         * ------------------------------- */
        File::deleteDirectory($chunksDir);
        File::deleteDirectory($tempDir);

        Log::info('finalize_completed', [
            'upload_id' => $uploadId,
            'path'      => $finalPath,
            'bytes'     => $written,
        ]);

        return response()->json([
            'finalized' => true,
            'bytes'     => $written,
            'path'      => $finalPath,
        ]);
    }
}
