import { Container } from "@cloudflare/containers";
import { authenticate, enforceRateLimit, requireAnyScope, requireScope, whoamiBody } from "./auth";
import { jsonError, jsonResponse, readBody } from "./http";
import { renderPdf } from "./pdf";
import { assertTemplateId, deleteTemplate, getTemplate, listTemplates, putTemplate, templateId } from "./templates";
import presets from "../../presets.json";

export class PdfContainer extends Container<Env> {
  defaultPort = 8080;
  sleepAfter = "2m";

  override onError(error: unknown): unknown {
    console.log(JSON.stringify({
      message: "container error",
      error: error instanceof Error ? error.message : "unknown",
    }));
    return error;
  }
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const requestId = crypto.randomUUID();
    const url = new URL(request.url);
    const path = url.pathname.length > 1 && url.pathname.endsWith("/")
      ? url.pathname.slice(0, -1)
      : url.pathname;
    try {
      const response = await route(request, env, url, path, requestId);
      if (response.status >= 500) {
        console.log(JSON.stringify({ request_id: requestId, status: response.status, path }));
      }
      return response;
    } catch (error) {
      console.log(JSON.stringify({
        request_id: requestId,
        path,
        message: error instanceof Error ? error.message : "internal",
      }));
      return jsonError(500, "internal", "Internal error", requestId);
    }
  },
} satisfies ExportedHandler<Env>;

async function route(request: Request, env: Env, url: URL, path: string, requestId: string): Promise<Response> {
  if (path === "/v1/whoami" && request.method === "GET") {
    const auth = await authenticate(request, env, requestId);
    if (auth instanceof Response) {
      return auth;
    }
    return jsonResponse(200, whoamiBody(auth.token), requestId);
  }
  if (path === "/v1/capabilities" && request.method === "GET") {
    const auth = await authenticate(request, env, requestId);
    if (auth instanceof Response) {
      return auth;
    }
    return jsonResponse(200, {
      mpdf_version: presets.mpdf_version,
      max_html_bytes: presets.max_html_bytes,
      max_body_bytes: presets.max_body_bytes,
      fonts: presets.fonts,
      papers: presets.papers,
    }, requestId);
  }
  if ((path === "/v1/pdf" || path === "/v1/expand") && request.method === "POST") {
    const auth = await authenticate(request, env, requestId);
    if (auth instanceof Response) {
      return auth;
    }
    const forbidden = requireScope(auth.token, "render", requestId);
    if (forbidden) {
      return forbidden;
    }
    const limited = await enforceRateLimit(env, auth.hash, auth.token, requestId);
    if (limited) {
      return limited;
    }
    const raw = await readBody(request, presets.max_body_bytes, requestId);
    if (raw instanceof Response) {
      return raw;
    }
    return renderPdf(env, auth.token, raw, requestId, path === "/v1/expand");
  }
  if (path === "/v1/templates" && request.method === "GET") {
    const auth = await authenticate(request, env, requestId);
    if (auth instanceof Response) {
      return auth;
    }
    const forbidden = requireAnyScope(auth.token, ["render", "templates:write"], requestId);
    if (forbidden) {
      return forbidden;
    }
    return listTemplates(env, auth.token.owner, url.searchParams.get("cursor"), requestId);
  }
  const id = templateId(path);
  if (id && (request.method === "GET" || request.method === "PUT" || request.method === "DELETE")) {
    const badId = assertTemplateId(id, requestId);
    if (badId) {
      return badId;
    }
    const auth = await authenticate(request, env, requestId);
    if (auth instanceof Response) {
      return auth;
    }
    if (request.method === "GET") {
      const forbidden = requireAnyScope(auth.token, ["render", "templates:write"], requestId);
      if (forbidden) {
        return forbidden;
      }
      return getTemplate(env, auth.token.owner, id, requestId);
    }
    const forbidden = requireScope(auth.token, "templates:write", requestId);
    if (forbidden) {
      return forbidden;
    }
    const limited = await enforceRateLimit(env, auth.hash, auth.token, requestId);
    if (limited) {
      return limited;
    }
    if (request.method === "DELETE") {
      return deleteTemplate(env, auth.token.owner, id, requestId);
    }
    const raw = await readBody(request, presets.max_body_bytes, requestId);
    if (raw instanceof Response) {
      return raw;
    }
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw);
    } catch {
      return jsonError(400, "invalid_json", "Request body must be JSON", requestId);
    }
    return putTemplate(env, auth.token.owner, id, parsed, requestId);
  }
  if (path.startsWith("/v1/")) {
    return jsonError(404, "not_found", "Not found", requestId);
  }
  return jsonError(404, "not_found", "Not found", requestId);
}
