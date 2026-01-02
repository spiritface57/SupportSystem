import net from "net";
import express from "express";
import crypto from "crypto";

const app = express();

app.post("/scan", (req, res) => {
  const scanId = crypto.randomUUID();
  const start = Date.now();

  console.log(`[scan:${scanId}] request received`);

  const socket = net.createConnection({ path: "/run/clamav/clamd.sock" });

  let response = "";
  let finished = false;
  let phase = "init";
  let bytesSent = 0;

  const fail = (reason, details = {}) => {
    if (finished) return;
    finished = true;

    console.error(`[scan:${scanId}] failed`, {
      phase,
      reason,
      ...details,
    });

    socket.destroy();

    return res.status(500).json({
      status: "error",
      reason,
      phase,
    });
  };

  socket.setTimeout(10000);

  socket.on("timeout", () =>
    fail("clamd_timeout", { duration_ms: Date.now() - start })
  );

  socket.on("error", (err) =>
    fail("clamd_socket_error", { error: err.message })
  );

  // ---- INSTREAM init ----
  try {
    socket.write(Buffer.from("zINSTREAM\0"));
    phase = "streaming";
  } catch (e) {
    return fail("instream_init_failed", { error: e.message });
  }

  // ---- Stream file ----
  req.on("data", (chunk) => {
    if (finished) return;

    try {
      const len = Buffer.alloc(4);
      len.writeUInt32BE(chunk.length);

      socket.write(len);
      socket.write(chunk);

      bytesSent += chunk.length;
    } catch (e) {
      return fail("stream_write_failed", {
        error: e.message,
        bytes_sent: bytesSent,
      });
    }
  });

  req.on("end", () => {
    if (finished) return;

    phase = "stream_end";

    try {
      socket.write(Buffer.alloc(4)); // end of stream
    } catch (e) {
      return fail("stream_finalize_failed", { error: e.message });
    }
  });

  req.on("error", (err) =>
    fail("request_stream_error", { error: err.message })
  );

  // ---- Read clamd response ----
  socket.on("data", (data) => {
    phase = "reading_response";
    response += data.toString();
  });

  socket.on("end", () => {
    if (finished) return;
    finished = true;

    const duration = Date.now() - start;

    console.log(`[scan:${scanId}] completed`, {
      duration_ms: duration,
      bytes_sent: bytesSent,
      response: response.trim(),
      found: response.includes("FOUND"),
    });

    if (response.includes("FOUND")) {
      return res.status(200).json({
        status: "infected",
        signature: response.trim(),
        bytes_scanned: bytesSent,
        duration_ms: duration,
      });
    }

    if (response.includes("OK")) {
      return res.json({
        status: "clean",
        bytes_scanned: bytesSent,
        duration_ms: duration,
      });
    }

    return res.status(500).json({
      status: "error",
      reason: "unexpected_clamd_response",
      response: response.trim(),
      bytes_scanned: bytesSent,
      duration_ms: duration,
    });
  });
});

app.listen(3001, () => {
  console.log("Scanner service listening on :3001");
});
