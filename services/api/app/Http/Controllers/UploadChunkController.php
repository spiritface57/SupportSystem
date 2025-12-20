<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UploadChunkController extends Controller
{
    public function __invoke(Request $request)
    {

        $data = $request->validate([
            'upload_id' => 'required|string',
            'index'     => 'required|integer|min:0',
            'chunk'     => 'required|file',
        ]);

        $uploadId = $data['upload_id'];
        $index    = $data['index'];
        $file     = $data['chunk'];

        // v0.1: dumb disk write, no guarantees
        $dir = storage_path("app/uploads/{$uploadId}");

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file->move($dir, "{$index}.part");

        return response()->json([
            'received' => true,
            'index'    => $index,
        ]);
    }
}
