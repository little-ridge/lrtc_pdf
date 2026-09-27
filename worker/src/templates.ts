import { jsonError, jsonResponse, byteLength, isRecord } from "./http";
import { parseManifest, type FieldManifest } from "./fields";
import { seedDocument, seedDocuments, type TemplateDocument } from "./seeds";
import presets from "../../presets.json";

const ID_PATTERN = /^[a-z0-9][a-z0-9-]{0,63}$/;

export function templateId(pathname: string): string | null {
  const match = /^\/v1\/templates\/([a-z0-9][a-z0-9-]{0,63})$/.exec(pathname);
  return match ? match[1] : null;
}

export function assertTemplateId(id: string, requestId: string): Response | null {
  if (!ID_PATTERN.test(id)) {
    return jsonError(
      400,
      "invalid_template_id",
      "Template id must be lowercase letters, digits, and hyphens",
      requestId,
    );
  }
  return null;
}

export async function listTemplates(
  env: Env,
  owner: string,
  cursor: string | null,
  requestId: string,
): Promise<Response> {
  const listed = await env.TEMPLATES.list({
    prefix: prefix(owner),
    delimiter: "/",
    cursor: cursor ?? undefined,
    limit: 50,
  });
  const ids = new Set<string>();
  for (const delimited of listed.delimitedPrefixes) {
    const id = idFromPrefix(owner, delimited);
    if (id) {
      ids.add(id);
    }
  }
  const summaries = new Map<string, Record<string, unknown>>();
  if (cursor === null) {
    for (const seed of seedDocuments()) {
      summaries.set(seed.id, summary(seed));
    }
  }
  for (const id of ids) {
    const document = await readStored(env, owner, id);
    if (document) {
      summaries.set(id, summary(document));
    }
  }
  return jsonResponse(200, {
    templates: [...summaries.values()],
    cursor: listed.truncated ? listed.cursor : null,
  }, requestId);
}

export async function getTemplate(env: Env, owner: string, id: string, requestId: string): Promise<Response> {
  const stored = await readStored(env, owner, id);
  const document = stored ?? seedDocument(id);
  if (!document) {
    return jsonError(404, "not_found", "Template not found", requestId);
  }
  return jsonResponse(200, publicDocument(document), requestId);
}

export async function putTemplate(
  env: Env,
  owner: string,
  id: string,
  body: unknown,
  requestId: string,
): Promise<Response> {
  if (!isRecord(body)) {
    return jsonError(400, "invalid_json", "Request body must be a JSON object", requestId);
  }
  if (typeof body.html !== "string" || body.html === "") {
    return jsonError(400, "invalid_request", "html is required", requestId);
  }
  const tooBig = htmlTooBig(body.html, "html", requestId)
    ?? optionalHtmlTooBig(body.header_html, "header_html", requestId)
    ?? optionalHtmlTooBig(body.footer_html, "footer_html", requestId);
  if (tooBig) {
    return tooBig;
  }
  const header = optionalHtml(body.header_html, "header_html", requestId);
  if (header instanceof Response) {
    return header;
  }
  const footer = optionalHtml(body.footer_html, "footer_html", requestId);
  if (footer instanceof Response) {
    return footer;
  }
  const paper = body.paper === undefined ? "letter" : body.paper;
  if (typeof paper !== "string" || !Object.hasOwn(presets.papers, paper)) {
    return jsonError(400, "invalid_paper", "Unknown paper preset", requestId, {
      allowed: Object.keys(presets.papers),
    });
  }
  const defaultFont = body.default_font === undefined ? "dejavusans" : body.default_font;
  if (typeof defaultFont !== "string" || !presets.fonts.includes(defaultFont)) {
    return jsonError(400, "invalid_font", "Unknown font", requestId, { allowed: presets.fonts });
  }
  const manifest = parseManifest(body.fields);
  if (!manifest.ok) {
    return jsonError(400, "invalid_template", "Field manifest is malformed", requestId);
  }
  const sample = parseSample(body.sample, requestId);
  if (sample instanceof Response) {
    return sample;
  }
  const updated = new Date().toISOString();
  const base = `${prefix(owner)}${id}/`;
  await env.TEMPLATES.put(`${base}body.html`, body.html, {
    httpMetadata: { contentType: "text/html; charset=utf-8" },
  });
  await writeOptional(env, `${base}header.html`, header);
  await writeOptional(env, `${base}footer.html`, footer);
  await env.TEMPLATES.put(`${base}meta.json`, JSON.stringify({
    paper,
    default_font: defaultFont,
    fields: manifest.manifest,
    sample,
    updated,
  }), {
    httpMetadata: { contentType: "application/json; charset=utf-8" },
  });
  const document: TemplateDocument = {
    id,
    html: body.html,
    header_html: header,
    footer_html: footer,
    paper,
    default_font: defaultFont,
    fields: manifest.manifest,
    sample,
    updated,
    seed: seedDocument(id) !== null,
  };
  return jsonResponse(200, publicDocument(document), requestId);
}

export async function deleteTemplate(env: Env, owner: string, id: string, requestId: string): Promise<Response> {
  const base = `${prefix(owner)}${id}/`;
  let cursor: string | undefined;
  let deleted = 0;
  do {
    const page = await env.TEMPLATES.list({ prefix: base, cursor });
    await Promise.all(page.objects.map((object) => env.TEMPLATES.delete(object.key)));
    deleted += page.objects.length;
    cursor = page.truncated ? page.cursor : undefined;
  } while (cursor);
  if (deleted === 0) {
    return jsonError(404, "not_found", "Template not found", requestId);
  }
  return jsonResponse(200, { deleted: true, seed: seedDocument(id) !== null }, requestId);
}

export async function loadTemplate(env: Env, owner: string, id: string): Promise<TemplateDocument | null> {
  return (await readStored(env, owner, id)) ?? seedDocument(id);
}

function summary(document: TemplateDocument): Record<string, unknown> {
  return {
    id: document.id,
    paper: document.paper,
    updated: document.updated,
    fields: document.fields ? Object.keys(document.fields) : [],
  };
}

function publicDocument(document: TemplateDocument): Record<string, unknown> {
  return {
    id: document.id,
    html: document.html,
    header_html: document.header_html,
    footer_html: document.footer_html,
    paper: document.paper,
    default_font: document.default_font,
    fields: document.fields,
    sample: document.sample,
    updated: document.updated,
  };
}

async function readStored(env: Env, owner: string, id: string): Promise<TemplateDocument | null> {
  const base = `${prefix(owner)}${id}/`;
  const bodyObject = await env.TEMPLATES.get(`${base}body.html`);
  if (!bodyObject) {
    return null;
  }
  const html = await bodyObject.text();
  const metaObject = await env.TEMPLATES.get(`${base}meta.json`);
  let paper = "letter";
  let defaultFont = "dejavusans";
  let fields: FieldManifest | null = null;
  let sample: Record<string, unknown> | null = null;
  let updated: string | null = null;
  if (metaObject) {
    try {
      const meta = JSON.parse(await metaObject.text()) as unknown;
      if (isRecord(meta)) {
        if (typeof meta.paper === "string" && Object.hasOwn(presets.papers, meta.paper)) {
          paper = meta.paper;
        }
        if (typeof meta.default_font === "string" && presets.fonts.includes(meta.default_font)) {
          defaultFont = meta.default_font;
        }
        const manifest = parseManifest(meta.fields);
        fields = manifest.ok ? manifest.manifest : null;
        if (isRecord(meta.sample)) {
          sample = meta.sample;
        }
        if (typeof meta.updated === "string") {
          updated = meta.updated;
        }
      }
    } catch {
      fields = null;
    }
  }
  const headerObject = await env.TEMPLATES.get(`${base}header.html`);
  const footerObject = await env.TEMPLATES.get(`${base}footer.html`);
  return {
    id,
    html,
    header_html: headerObject ? await headerObject.text() : null,
    footer_html: footerObject ? await footerObject.text() : null,
    paper,
    default_font: defaultFont,
    fields,
    sample,
    updated,
    seed: false,
  };
}

async function writeOptional(env: Env, key: string, value: string | null): Promise<void> {
  if (value === null) {
    await env.TEMPLATES.delete(key);
    return;
  }
  await env.TEMPLATES.put(key, value, {
    httpMetadata: { contentType: "text/html; charset=utf-8" },
  });
}

function optionalHtml(value: unknown, field: string, requestId: string): string | null | Response {
  if (value === undefined || value === null) {
    return null;
  }
  if (typeof value !== "string") {
    return jsonError(400, "invalid_request", `${field} must be a string`, requestId);
  }
  return value;
}

function optionalHtmlTooBig(value: unknown, field: string, requestId: string): Response | null {
  if (typeof value !== "string") {
    return null;
  }
  return htmlTooBig(value, field, requestId);
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

function parseSample(value: unknown, requestId: string): Record<string, unknown> | null | Response {
  if (value === undefined || value === null) {
    return null;
  }
  if (!isRecord(value)) {
    return jsonError(400, "invalid_request", "sample must be an object", requestId);
  }
  return value;
}

function prefix(owner: string): string {
  return `owners/${owner}/templates/`;
}

function idFromPrefix(owner: string, delimited: string): string | null {
  const base = prefix(owner);
  if (!delimited.startsWith(base)) {
    return null;
  }
  const id = delimited.slice(base.length).replace(/\/$/, "");
  if (id.includes("/") || !ID_PATTERN.test(id)) {
    return null;
  }
  return id;
}
