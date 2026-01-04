<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UploadMetricsReport extends Command
{
    protected $signature = 'upload:metrics-report {--out=docs/posts/post8/metrics-output.md}';
    protected $description = 'Generate Post 8 metrics report from upload_events (SQLite)';

    public function handle(): int
    {
        $out = (string) $this->option('out');

        $counts = DB::table('upload_events')
            ->select('event_name', DB::raw('COUNT(*) as c'))
            ->groupBy('event_name')
            ->orderByDesc('c')
            ->get();

        $finalized = DB::table('upload_events')
            ->where('event_name', 'upload.finalized')
            ->select('payload')
            ->get();

        $byStatus = [];
        $allDur = [];

        foreach ($finalized as $row) {
            $p = json_decode($row->payload, true) ?: [];
            $status = (string)($p['status'] ?? 'unknown');
            $dur = isset($p['duration_ms']) ? (int)$p['duration_ms'] : null;

            $byStatus[$status] ??= ['n' => 0, 'dur' => []];
            $byStatus[$status]['n']++;

            if (is_int($dur)) {
                $byStatus[$status]['dur'][] = $dur;
                $allDur[] = $dur;
            }
        }

        $publishedCount = (int) DB::table('upload_events')
            ->where('event_name', 'upload.published')
            ->count();

        $lines = [];
        $lines[] = "# Post 8 Metrics (Measured)";
        $lines[] = "";
        $lines[] = "- Generated at: " . now()->toISOString();
        $lines[] = "- DB driver: " . config('database.default');
        $lines[] = "";

        $lines[] = "## Event Counts";
        $lines[] = "";
        $lines[] = "| Event | Count |";
        $lines[] = "|---|---:|";
        foreach ($counts as $c) {
            $lines[] = "| `{$c->event_name}` | {$c->c} |";
        }
        $lines[] = "";

        $lines[] = "## Finalize Latency (ms) by Status";
        $lines[] = "";
        $lines[] = "| Status | N | avg | p50 | p95 | max |";
        $lines[] = "|---|---:|---:|---:|---:|---:|";

        foreach ($byStatus as $status => $grp) {
            $dur = $grp['dur'];
            sort($dur);
            $n = (int)$grp['n'];

            $avg = $this->avg($dur);
            $p50 = $this->pct($dur, 50);
            $p95 = $this->pct($dur, 95);
            $max = $dur ? $dur[count($dur) - 1] : null;

            $lines[] = "| `{$status}` | {$n} | " .
                ($avg ?? '-') . " | " .
                ($p50 ?? '-') . " | " .
                ($p95 ?? '-') . " | " .
                ($max ?? '-') . " |";
        }
        $lines[] = "";

        sort($allDur);
        $lines[] = "## Overall Finalize Latency (ms)";
        $lines[] = "";
        $lines[] = "- N: " . count($allDur);
        $lines[] = "- avg: " . ($this->avg($allDur) ?? '-');
        $lines[] = "- p50: " . ($this->pct($allDur, 50) ?? '-');
        $lines[] = "- p95: " . ($this->pct($allDur, 95) ?? '-');
        $lines[] = "- max: " . ($allDur ? $allDur[count($allDur) - 1] : '-');
        $lines[] = "";

        $lines[] = "## Rescan Publish";
        $lines[] = "";
        $lines[] = "- upload.published count: {$publishedCount}";
        $lines[] = "";

        file_put_contents(base_path($out), implode("\n", $lines) . "\n");

        $this->info("Wrote metrics report to: {$out}");
        return self::SUCCESS;
    }

    private function avg(array $xs): ?int
    {
        if (!$xs) return null;
        return (int) round(array_sum($xs) / count($xs));
    }

    private function pct(array $xs, int $p): ?int
    {
        $n = count($xs);
        if ($n === 0) return null;
        $idx = (int) floor(($p / 100) * ($n - 1));
        return $xs[$idx] ?? null;
    }
}
