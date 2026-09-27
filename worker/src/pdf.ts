import { getRandom } from "@cloudflare/containers";
import { jsonError, jsonResponse, byteLength, isRecord } from "./http";
import { validateData, type FieldManifest } from "./fields";
import { loadTemplate } from "./templates";
import type { TokenRecord } from "./auth";
import presets from "../../presets.json";

const OPTION_KEYS = new Set([
  "paper",
  "default_font",
  "title",
  "author",
  "subject",
  "keywords",
  "filename",
  "disposition",
]);

const POOL = 3;

export async function renderPdf(
  env: Env,
  token: TokenRecord,
  raw: string,
  requestId: string,
  expand: boolean,
): Promise<Response> {
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return jsonError(400, "invalid_json", "Request body must be JSON", requestId);
  }
  if (!isRecord(parsed)) {
    return jsonError(400, "invalid_json", "Request body must be a JSON object", requestId);
  }
  const built = await buildPayload(env, token, parsed, requestId);
  if (built instanceof Response) {
    return built;
  }
  const container = await getRandom(env.PDF_CONTAINER, POOL);
  const upstream = await container.fetch("http://container/" + (expand ? "expand" : "render"), {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(built.payload),
  });
  if (expand || !upstream.ok || !(upstream.headers.get("content-type") ?? "").includes("application/pdf")) {
    return proxyJson(upstream, requestId);
  }
  const headers = new Headers();
  headers.set("content-type", "application/pdf");
  headers.set("content-disposition", contentDisposition(built.disposition, built.filename));
  headers.set("x-request-id", requestId);
  headers.set("cache-control", "no-store");
  const pages = upstream.headers.get("x-pdf-pages");
  const bytes = upstream.headers.get("x-pdf-bytes");
  if (pages) {
    headers.set("x-pdf-pages", pages);
  }
  if (bytes) {
    headers.set("x-pdf-bytes", bytes);
  }
  return new Response(upstream.body, { status: 200, headers });
}

async function proxyJson(upstream: Response, requestId: string): Promise<Response> {
  const text = await upstream.text();
  let payload: unknown;
  try {
    payload = JSON.parse(text);
  } catch {
    return jsonError(502, "bad_gateway", "Container returned an invalid response", requestId);
  }
  if (!isRecord(payload)) {
    return jsonError(502, "bad_gateway", "Container returned an invalid response", requestId);
  }
  payload.request_id = requestId;
  return jsonResponse(upstream.status, payload, requestId);
}

async function buildPayload(
  env: Env,
  token: TokenRecord,
  body: Record<string, unknown>,
  requestId: string,
): Promise<{ payload: Record<string, unknown>; filename: string; disposition: string } | Response> {
  const hasTemplate = typeof body.template === "string";
  const hasHtml = typeof body.html === "string";
  if (hasTemplate === hasHtml || (hasHtml && body.html === "")) {
    return jsonError(400, "invalid_request", "Provide exactly one of template or html", requestId);
  }
  if (body.data !== undefined && !isRecord(body.data)) {
    return jsonError(400, "invalid_request", "data must be an object", requestId);
  }
  const data = isRecord(body.data) ? body.data : {};
  const options = body.options === undefined ? {} : body.options;
  if (!isRecord(options)) {
    return jsonError(400, "invalid_request", "options must be an object", requestId);
  }
  for (const key of Object.keys(options)) {
    if (!OPTION_KEYS.has(key)) {
      return jsonError(400, "invalid_request", "Unknown option", requestId, { option: key });
    }
  }

  let html = hasHtml ? body.html as string : "";
  let header: string | null = null;
  let footer: string | null = null;
  let paper = "letter";
  let defaultFont = "dejavusans";
  let fields: FieldManifest | null = null;
  if (hasTemplate) {
    const id = body.template as string;
    if (!/^[a-z0-9][a-z0-9-]{0,63}$/.test(id)) {
      return jsonError(400, "invalid_template_id", "Template id must be lowercase letters, digits, and hyphens", requestId);
    }
    const document = await loadTemplate(env, token.owner, id);
    if (!document) {
      return jsonError(404, "not_found", "Template not found", requestId);
    }
    html = document.html;
    header = document.header_html;
    footer = document.footer_html;
    paper = document.paper;
    defaultFont = document.default_font;
    fields = document.fields;
  }

  const headerOverride = overrideString(body, "header_html", requestId);
  if (headerOverride instanceof Response) {
    return headerOverride;
  }
  if (headerOverride.present) {
    header = headerOverride.value;
  }
  const footerOverride = overrideString(body, "footer_html", requestId);
  if (footerOverride instanceof Response) {
    return footerOverride;
  }
  if (footerOverride.present) {
    footer = footerOverride.value;
  }

  if (typeof options.paper === "string") {
    paper = options.paper;
  } else if (options.paper !== undefined) {
    return jsonError(400, "invalid_request", "options.paper must be a string", requestId);
  }
  if (!Object.hasOwn(presets.papers, paper)) {
    return jsonError(400, "invalid_paper", "Unknown paper preset", requestId, {
      allowed: Object.keys(presets.papers),
    });
  }
  if (typeof options.default_font === "string") {
    defaultFont = options.default_font;
  } else if (options.default_font !== undefined) {
    return jsonError(400, "invalid_request", "options.default_font must be a string", requestId);
  }
  if (!presets.fonts.includes(defaultFont)) {
    return jsonError(400, "invalid_font", "Unknown font", requestId, { allowed: presets.fonts });
  }

  for (const key of ["title", "author", "subject", "keywords"] as const) {
    const value = options[key];
    if (value === undefined) {
      continue;
    }
    if (typeof value !== "string" || byteLength(value) > 200) {
      return jsonError(400, "invalid_request", `options.${key} must be a string`, requestId);
    }
  }
  const disposition = options.disposition === undefined ? "inline" : options.disposition;
  if (disposition !== "inline" && disposition !== "attachment") {
    return jsonError(400, "invalid_request", "options.disposition must be inline or attachment", requestId);
  }
  const filename = options.filename === undefined ? "document.pdf" : options.filename;
  if (typeof filename !== "string" || filename.length > 120 || /[\r\n"]/.test(filename)) {
    return jsonError(400, "invalid_request", "options.filename must be a string", requestId);
  }

  const sizeError = htmlTooBig(html, "html", requestId)
    ?? (header !== null ? htmlTooBig(header, "header_html", requestId) : null)
    ?? (footer !== null ? htmlTooBig(footer, "footer_html", requestId) : null);
  if (sizeError) {
    return sizeError;
  }

  const fieldErrors = validateData(fields, data);
  if (fieldErrors) {
    return jsonError(422, "invalid_fields", "The data does not match the template fields", requestId, fieldErrors);
  }

  const fill = hasTemplate || Object.keys(data).length > 0;
  return {
    filename,
    disposition,
    payload: {
      html,
      header_html: header,
      footer_html: footer,
      data,
      fields,
      fill,
      allow_any_host: true,
      options: {
        paper,
        default_font: defaultFont,
        title: typeof options.title === "string" ? options.title : "",
        author: typeof options.author === "string" ? options.author : "",
        subject: typeof options.subject === "string" ? options.subject : "",
        keywords: typeof options.keywords === "string" ? options.keywords : "",
        filename,
        disposition,
      },
    },
  };
}

function overrideString(
  body: Record<string, unknown>,
  key: string,
  requestId: string,
): { present: boolean; value: string | null } | Response {
  if (!Object.prototype.hasOwnProperty.call(body, key)) {
    return { present: false, value: null };
  }
  const value = body[key];
  if (value === null) {
    return { present: true, value: null };
  }
  if (typeof value !== "string") {
    return jsonError(400, "invalid_request", `${key} must be a string`, requestId);
  }
  return { present: true, value };
}

function htmlTooBig(value: string, field: string, requestId: string): Response | null {
  if (byteLength(value) <= presets.max_html_bytes) {
    return null;
  }
  return jsonError(413, "payload_too_large", `${field} exceeds the size limit`, requestId, {
    field,
    max_bytes: presets.max_html_bytes,
  });
}

function contentDisposition(disposition: string, filename: string): string {
  const safe = filename.replace(/[^A-Za-z0-9._-]/g, "_").slice(0, 120) || "document.pdf";
  const withPdf = safe.toLowerCase().endsWith(".pdf") ? safe : `${safe}.pdf`;
  return `${disposition}; filename="${withPdf}"`;
}
