<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UploadInitController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'filename'     => 'required|string|max:255',
            'total_bytes'  => 'required|integer|min:1',
            'chunk_bytes'  => 'required|integer|min:1024|max:10485760',
        ]);

        $uploadId = (string) Str::uuid();

        // v0.1: no persistence, no guarantees
        // just acknowledge intent

        return response()->json([
            'upload_id' => $uploadId,
        ], 201);
    }
}
