export function jsonResponse(
  status: number,
  body: unknown,
  requestId: string,
  extra?: HeadersInit,
): Response {
  const headers = new Headers(extra);
  headers.set("content-type", "application/json; charset=utf-8");
  headers.set("x-request-id", requestId);
  return new Response(JSON.stringify(body), { status, headers });
}

export function jsonError(
  status: number,
  error: string,
  message: string,
  requestId: string,
  details: unknown = null,
  extra?: HeadersInit,
): Response {
  return jsonResponse(status, { error, message, request_id: requestId, details }, requestId, extra);
}

export async function readBody(request: Request, maxBytes: number, requestId: string): Promise<string | Response> {
  const declared = request.headers.get("content-length");
  if (declared !== null && Number(declared) > maxBytes) {
    return jsonError(413, "payload_too_large", "Request body exceeds the size limit", requestId, {
      max_bytes: maxBytes,
    });
  }
  if (!request.body) {
    return "";
  }
  const reader = request.body.getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) {
      break;
    }
    total += value.byteLength;
    if (total > maxBytes) {
      await reader.cancel();
      return jsonError(413, "payload_too_large", "Request body exceeds the size limit", requestId, {
        max_bytes: maxBytes,
      });
    }
    chunks.push(value);
  }
  const bytes = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return new TextDecoder().decode(bytes);
}

export function byteLength(value: string): number {
  return new TextEncoder().encode(value).byteLength;
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
