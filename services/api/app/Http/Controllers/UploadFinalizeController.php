<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class UploadFinalizeController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'upload_id' => 'required|string',
            'filename'  => 'required|string|max:255',
            'total_bytes' => 'required|integer|min:1',
        ]);

        $uploadId   = $data['upload_id'];
        $filename   = $data['filename'];
        $totalBytes = $data['total_bytes'];

        $chunksDir = storage_path("app/uploads/{$uploadId}");
        $finalDir  = storage_path("app/final");
        $finalPath = "{$finalDir}/{$filename}";

        if (!is_dir($chunksDir)) {
            return response()->json([
                'error' => 'chunks_not_found',
            ], 400);
        }

        if (!is_dir($finalDir)) {
            mkdir($finalDir, 0777, true);
        }

        if (file_exists($finalPath)) {
            return response()->json([
                'error' => 'file_collision',
            ], 409);
        }

        $out = fopen($finalPath, 'wb');
        $written = 0;

        $chunks = collect(File::files($chunksDir))
            ->sortBy(fn($f) => intval(pathinfo($f->getFilename(), PATHINFO_FILENAME)));

        foreach ($chunks as $chunk) {
            $data = file_get_contents($chunk->getPathname());
            fwrite($out, $data);
            $written += strlen($data);
        }

        fclose($out);

        if ((int)$written !== (int)$totalBytes) {
            return response()->json([
                'error' => 'size_mismatch',
                'written' => $written,
            ], 400);
        }

        // cleanup (best effort)
        File::deleteDirectory($chunksDir);

        return response()->json([
            'finalized' => true,
            'bytes'     => $written,
            'path'      => $finalPath,
        ]);
    }
}
