<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Support\UploadEventEmitter;


class UploadInitController extends Controller
{
    public function __invoke(Request $request)
    {
        try {
            $data = $request->validate([
                'filename'     => 'required|string|max:255',
                'total_bytes'  => 'required|integer|min:1',
                'chunk_bytes'  => 'required|integer|min:1024|max:10485760',
            ]);

            $uploadId = (string) Str::uuid();
            UploadEventEmitter::emit(
                'upload.initiated',
                $uploadId,
                'api',
                [
                    'filename'    => $data['filename'],
                    'total_bytes' => $data['total_bytes'],
                    'chunk_bytes' => $data['chunk_bytes'],
                ]
            );
            // v0.1: no persistence, no guarantees
            // just acknowledge intent

            return response()->json([
                'upload_id' => $uploadId,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error($e);

            return response()->json([
                'message' => 'Internal Server Error',
            ], 500);
        }
    }
}
