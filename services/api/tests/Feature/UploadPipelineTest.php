<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Illuminate\Support\Facades\Http;


class UploadPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('app/uploads'));
        File::ensureDirectoryExists(storage_path('app/uploads-meta'));
        File::ensureDirectoryExists(storage_path('app/tmp/locks'));
        File::ensureDirectoryExists(storage_path('app/tmp'));
        File::ensureDirectoryExists(storage_path('app/final/uploads'));
        File::ensureDirectoryExists(storage_path('app/quarantine/uploads'));
    }

    public function test_init_returns_upload_contract(): void
    {
        $res = $this->postJson('/api/upload/init', [
            'filename'    => 'a.png',
            'total_bytes' => 2059906,
            'chunk_bytes' => 1048576,
        ]);

        $res->assertStatus(201)
            ->assertJsonStructure([
                'upload_id',
                'filename',
                'total_bytes',
                'chunk_bytes',
            ]);

        $this->assertSame('a.png', $res->json('filename'));
        $this->assertSame(2059906, $res->json('total_bytes'));
        $this->assertSame(1048576, $res->json('chunk_bytes'));
    }

    public function test_chunk_rejects_out_of_range_index(): void
    {
        // Contract: chunk_bytes must be >= 1024
        $chunkBytes = 1024;
        $totalBytes = 1028; // expectedChunks = ceil(1028/1024) = 2, valid indexes: 0,1

        $init = $this->postJson('/api/upload/init', [
            'filename'    => 'a.bin',
            'total_bytes' => $totalBytes,
            'chunk_bytes' => $chunkBytes,
        ])->assertStatus(201);

        $uploadId = $init->json('upload_id');

        // any content; request should fail before disk write due to index
        $file = UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('x', 4));

        $res = $this->post('/api/upload/chunk', [
            'upload_id' => $uploadId,
            'index'     => 2, // out of range
            'chunk'     => $file,
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'error'  => 'invalid_chunk_index',
                'reason' => 'index_out_of_range',
                'index'  => 2,
                'max'    => 1,
            ]);
    }

    public function test_chunk_rejects_invalid_non_last_chunk_size(): void
    {
        $chunkBytes = 1024;
        $totalBytes = 1028; // expectedChunks=2; index 0 is non-last and MUST be exactly 1024 bytes

        $init = $this->postJson('/api/upload/init', [
            'filename'    => 'a.bin',
            'total_bytes' => $totalBytes,
            'chunk_bytes' => $chunkBytes,
        ])->assertStatus(201);

        $uploadId = $init->json('upload_id');

        // wrong size for non-last chunk (should be 1024)
        $file = UploadedFile::fake()->createWithContent('chunk0.bin', str_repeat('a', 1023));

        $res = $this->post('/api/upload/chunk', [
            'upload_id' => $uploadId,
            'index'     => 0,
            'chunk'     => $file,
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'error'  => 'invalid_chunk_size',
                'reason' => 'non_last_chunk_must_match_chunk_bytes',
                'index'  => 0,
                'expected_bytes' => 1024,
                'got_bytes'      => 1023,
            ]);
    }

    public function test_finalize_returns_missing_chunks_list(): void
    {
        $chunkBytes = 1024;
        $totalBytes = 1028; // expectedChunks = 2; indexes 0 and 1; last chunk = 4 bytes

        $init = $this->postJson('/api/upload/init', [
            'filename'    => 'a.bin',
            'total_bytes' => $totalBytes,
            'chunk_bytes' => $chunkBytes,
        ])->assertStatus(201);

        $uploadId = $init->json('upload_id');

        // Upload only chunk 0 (exact 1024)
        $chunk0 = UploadedFile::fake()->createWithContent('chunk0.bin', str_repeat('a', 1024));
        $this->post('/api/upload/chunk', [
            'upload_id' => $uploadId,
            'index'     => 0,
            'chunk'     => $chunk0,
        ])->assertStatus(200);

        // Finalize should be blocked with missing [1]
        $res = $this->postJson('/api/upload/finalize', [
            'upload_id'   => $uploadId,
            'filename'    => 'a.bin',
            'total_bytes' => $totalBytes,
        ]);

        $res->assertStatus(409)
            ->assertJson([
                'error'           => 'upload_failed',
                'reason'          => 'finalize_missing_chunks',
                'expected_chunks' => 2,
                'missing_count'   => 1,
            ]);

        $this->assertSame([1], $res->json('missing'));
    }

    public function test_happy_path_full_upload_then_finalize_degrades_to_pending_scan_and_quarantines_file(): void
    {
        // Force scanner unavailable => pending_scan
        config()->set('scanner.base_url', 'http://127.0.0.1:59999');
        config()->set('scanner.timeout', 1);

        $chunkBytes = 1024;
        $totalBytes = 1028; // chunk0=1024, chunk1=4

        $init = $this->postJson('/api/upload/init', [
            'filename'    => 'a.bin',
            'total_bytes' => $totalBytes,
            'chunk_bytes' => $chunkBytes,
        ])->assertStatus(201);

        $uploadId = $init->json('upload_id');

        // Upload chunk 0 (exact 1024)
        $chunk0 = UploadedFile::fake()->createWithContent('chunk0.bin', str_repeat('a', 1024));
        $this->post('/api/upload/chunk', [
            'upload_id' => $uploadId,
            'index'     => 0,
            'chunk'     => $chunk0,
        ])->assertStatus(200);

        // Upload chunk 1 (exact remaining 4)
        $chunk1 = UploadedFile::fake()->createWithContent('chunk1.bin', str_repeat('b', 4));
        $this->post('/api/upload/chunk', [
            'upload_id' => $uploadId,
            'index'     => 1,
            'chunk'     => $chunk1,
        ])->assertStatus(200);

        // Finalize
        $res = $this->postJson('/api/upload/finalize', [
            'upload_id'   => $uploadId,
            'filename'    => 'a.bin',
            'total_bytes' => $totalBytes,
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'finalized' => true,
                'bytes'     => 1028,
                'status'    => 'pending_scan',
            ]);

        // Quarantine must exist
        $quarantinePath = storage_path("app/quarantine/uploads/{$uploadId}/a.bin");
        $this->assertFileExists($quarantinePath);
        $this->assertSame(1028, filesize($quarantinePath));

        // upload.finalized event should be recorded
        $count = DB::table('upload_events')
            ->where('upload_id', $uploadId)
            ->where('event_name', 'upload.finalized')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_worker_rescans_pending_and_publishes_clean_file(): void
    {
        // 1) Create pending_scan upload by making scanner unavailable during finalize
        config()->set('scanner.base_url', 'http://127.0.0.1:59999');
        config()->set('scanner.timeout', 1);

        $chunkBytes = 1024;
        $totalBytes = 1028;

        $init = $this->postJson('/api/upload/init', [
            'filename'    => 'a.bin',
            'total_bytes' => $totalBytes,
            'chunk_bytes' => $chunkBytes,
        ])->assertStatus(201);

        $uploadId = $init->json('upload_id');

        $chunk0 = UploadedFile::fake()->createWithContent('chunk0.bin', str_repeat('a', 1024));
        $this->post('/api/upload/chunk', [
            'upload_id' => $uploadId,
            'index'     => 0,
            'chunk'     => $chunk0,
        ])->assertStatus(200);

        $chunk1 = UploadedFile::fake()->createWithContent('chunk1.bin', str_repeat('b', 4));
        $this->post('/api/upload/chunk', [
            'upload_id' => $uploadId,
            'index'     => 1,
            'chunk'     => $chunk1,
        ])->assertStatus(200);

        $this->postJson('/api/upload/finalize', [
            'upload_id'   => $uploadId,
            'filename'    => 'a.bin',
            'total_bytes' => $totalBytes,
        ])->assertStatus(200)
            ->assertJson(['status' => 'pending_scan']);

        $quarantinePath = storage_path("app/quarantine/uploads/{$uploadId}/a.bin");
        $this->assertFileExists($quarantinePath);

        // 2) Now make scanner available via Http::fake and run worker command
        config()->set('scanner.base_url', 'http://scanner:3001');
        config()->set('scanner.timeout', 2);

        Http::fake([
            // Match any /scan request
            '*' => Http::response(['status' => 'clean'], 200),
        ]);

        $this->artisan('upload:rescan-pending --limit=50')
            ->assertExitCode(0);

        // 3) File must be published to final, quarantine payload deleted
        $finalPath = storage_path("app/final/uploads/{$uploadId}/a.bin");
        $this->assertFileExists($finalPath);
        $this->assertSame(1028, filesize($finalPath));

        $this->assertFileDoesNotExist($quarantinePath);

        // 4) Publish event recorded
        $publishedCount = DB::table('upload_events')
            ->where('upload_id', $uploadId)
            ->where('event_name', 'upload.published')
            ->count();

        $this->assertSame(1, $publishedCount);
    }
}
