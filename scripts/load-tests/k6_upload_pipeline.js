import http from "k6/http";
import { check, sleep } from "k6";
import { Trend, Rate } from "k6/metrics";

const API_BASE = __ENV.API_BASE || "http://localhost:8000/api/upload";
const FILE_PATH =
  __ENV.FILE_PATH || "../services/api/file-upload-scan/files/a.png";
const CHUNK_SIZE = parseInt(__ENV.CHUNK_SIZE || "1048576", 10);
const PAUSE_BETWEEN_CHUNKS_MS = parseInt(
  __ENV.PAUSE_BETWEEN_CHUNKS_MS || "0",
  10,
);

const VUS = parseInt(__ENV.VUS || "5", 10);
const DURATION = __ENV.DURATION || "30s";
const ITERATIONS = __ENV.ITERATIONS ? parseInt(__ENV.ITERATIONS, 10) : 0;

const SLO_ERROR_RATE = __ENV.SLO_ERROR_RATE || "";
const SLO_P95_INIT_MS = __ENV.SLO_P95_INIT_MS || "";
const SLO_P95_CHUNK_MS = __ENV.SLO_P95_CHUNK_MS || "";
const SLO_P95_FINALIZE_MS = __ENV.SLO_P95_FINALIZE_MS || "";
const SLO_P95_TOTAL_MS = __ENV.SLO_P95_TOTAL_MS || "";

const thresholds = {};
if (SLO_ERROR_RATE) thresholds.upload_errors = [`rate<=${SLO_ERROR_RATE}`];
if (SLO_P95_INIT_MS) thresholds.upload_init_ms = [`p(95)<=${SLO_P95_INIT_MS}`];
if (SLO_P95_CHUNK_MS)
  thresholds.upload_chunk_ms = [`p(95)<=${SLO_P95_CHUNK_MS}`];
if (SLO_P95_FINALIZE_MS)
  thresholds.upload_finalize_ms = [`p(95)<=${SLO_P95_FINALIZE_MS}`];
if (SLO_P95_TOTAL_MS)
  thresholds.upload_total_ms = [`p(95)<=${SLO_P95_TOTAL_MS}`];

export const options =
  ITERATIONS > 0
    ? { vus: VUS, iterations: ITERATIONS, thresholds }
    : { vus: VUS, duration: DURATION, thresholds };

const t_init = new Trend("upload_init_ms");
const t_chunk = new Trend("upload_chunk_ms");
const t_finalize = new Trend("upload_finalize_ms");
const t_total = new Trend("upload_total_ms");
const errorRate = new Rate("upload_errors");

const fileData = open(FILE_PATH, "b");
const fileSize = fileData.byteLength || fileData.length;
const baseName = FILE_PATH.split(/[\\/]/).pop();
const chunkCount = Math.ceil(fileSize / CHUNK_SIZE);

function jsonHeaders() {
  return { headers: { "Content-Type": "application/json" } };
}

function doInit(filename) {
  const payload = JSON.stringify({
    filename,
    total_bytes: fileSize,
    chunk_bytes: CHUNK_SIZE,
  });
  const res = http.post(`${API_BASE}/init`, payload, jsonHeaders());
  const ok = check(res, {
    "init status 200/201": (r) => r.status === 200 || r.status === 201,
  });
  if (!ok) errorRate.add(1);
  t_init.add(res.timings.duration);
  let uploadId = "";
  try {
    uploadId = res.json("upload_id");
  } catch (_) {}
  return uploadId;
}

function doChunk(uploadId, index, chunk) {
  const form = {
    upload_id: uploadId,
    index: `${index}`,
    chunk: http.file(chunk, `${baseName}.part${index}`),
  };
  const res = http.post(`${API_BASE}/chunk`, form);
  const ok = check(res, { "chunk status 200": (r) => r.status === 200 });
  if (!ok) errorRate.add(1);
  t_chunk.add(res.timings.duration);
  return ok;
}

function doFinalize(uploadId, filename) {
  const payload = JSON.stringify({
    upload_id: uploadId,
    filename,
    total_bytes: fileSize,
  });
  const res = http.post(`${API_BASE}/finalize`, payload, jsonHeaders());
  const ok = check(res, {
    "finalize status 200/201": (r) => r.status === 200 || r.status === 201,
  });
  if (!ok) errorRate.add(1);
  t_finalize.add(res.timings.duration);
  return ok;
}

function sliceChunk(start, end) {
  if (fileData.slice) {
    return fileData.slice(start, end);
  }
  return new Uint8Array(fileData).slice(start, end).buffer;
}

export default function () {
  const started = Date.now();
  const filename = `${baseName}-vu${__VU}-it${__ITER}`;

  const uploadId = doInit(filename);
  if (!uploadId) {
    errorRate.add(1);
    return;
  }

  for (let i = 0; i < chunkCount; i += 1) {
    const start = i * CHUNK_SIZE;
    const end = Math.min(start + CHUNK_SIZE, fileSize);
    const chunk = sliceChunk(start, end);
    const ok = doChunk(uploadId, i, chunk);
    if (!ok) return;
    if (PAUSE_BETWEEN_CHUNKS_MS > 0) sleep(PAUSE_BETWEEN_CHUNKS_MS / 1000);
  }

  doFinalize(uploadId, filename);
  t_total.add(Date.now() - started);
}
