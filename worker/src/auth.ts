import { jsonError, isRecord } from "./http";
import presets from "../../presets.json";

export interface RateLimit {
  limit: number;
  window_seconds: number;
}

export interface TokenRecord {
  name: string;
  owner: string;
  scopes: string[];
  allowed_hosts: string[];
  rate_limit: RateLimit;
}

const DEFAULT_RATE: RateLimit = { limit: 30, window_seconds: 60 };
const KNOWN_SCOPES = new Set(["render", "templates:write"]);

export async function sha256Hex(value: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value));
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

export async function authenticate(
  request: Request,
  env: Env,
  requestId: string,
): Promise<{ token: TokenRecord; hash: string } | Response> {
  const header = request.headers.get("authorization") ?? "";
  const match = /^Bearer\s+(\S+)$/i.exec(header);
  if (!match) {
    return jsonError(401, "unauthorized", "Bearer token required", requestId);
  }
  const hash = await sha256Hex(match[1]);
  const raw = await env.TOKENS.get(hash);
  if (raw === null) {
    return jsonError(401, "unauthorized", "Unknown token", requestId);
  }
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return jsonError(401, "invalid_token", "Token record is malformed", requestId);
  }
  const token = parseToken(parsed);
  if (token === null) {
    return jsonError(401, "invalid_token", "Token record is malformed", requestId);
  }
  if (token.disabled) {
    return jsonError(401, "unauthorized", "Token is disabled", requestId);
  }
  return { token: token.record, hash };
}

export function requireScope(token: TokenRecord, scope: string, requestId: string): Response | null {
  if (!token.scopes.includes(scope)) {
    return jsonError(403, "forbidden", "Token is missing the required scope", requestId, { scope });
  }
  return null;
}

export function requireAnyScope(token: TokenRecord, scopes: string[], requestId: string): Response | null {
  if (!scopes.some((scope) => token.scopes.includes(scope))) {
    return jsonError(403, "forbidden", "Token is missing the required scope", requestId, { scope: scopes });
  }
  return null;
}

export async function enforceRateLimit(
  env: Env,
  hash: string,
  token: TokenRecord,
  requestId: string,
): Promise<Response | null> {
  const windowSeconds = token.rate_limit.window_seconds;
  const limit = token.rate_limit.limit;
  const windowStart = Math.floor(Date.now() / 1000 / windowSeconds);
  const key = `rate:${hash}:${windowStart}`;
  const currentRaw = await env.TOKENS.get(key);
  const current = currentRaw === null ? 0 : Number.parseInt(currentRaw, 10);
  if (!Number.isFinite(current) || current >= limit) {
    return jsonError(429, "rate_limited", "Too many requests", requestId, {
      limit,
      window_seconds: windowSeconds,
    }, { "retry-after": String(windowSeconds) });
  }
  await env.TOKENS.put(key, String(current + 1), { expirationTtl: windowSeconds * 2 });
  return null;
}

export function whoamiBody(token: TokenRecord): Record<string, unknown> {
  return {
    name: token.name,
    owner: token.owner,
    scopes: token.scopes,
    allowed_hosts: token.allowed_hosts,
    limits: {
      max_html_bytes: presets.max_html_bytes,
      max_body_bytes: presets.max_body_bytes,
      rate_limit: token.rate_limit.limit,
      window_seconds: token.rate_limit.window_seconds,
    },
  };
}

function parseToken(value: unknown): { record: TokenRecord; disabled: boolean } | null {
  if (!isRecord(value)) {
    return null;
  }
  if (typeof value.name !== "string" || value.name.trim() === "" || value.name.length > 120) {
    return null;
  }
  if (typeof value.owner !== "string" || !/^[a-z0-9][a-z0-9_-]{0,63}$/.test(value.owner)) {
    return null;
  }
  if (!Array.isArray(value.scopes)) {
    return null;
  }
  const scopes = value.scopes.filter((scope): scope is string => typeof scope === "string" && KNOWN_SCOPES.has(scope));
  if (scopes.length === 0) {
    return null;
  }
  if (value.disabled !== undefined && typeof value.disabled !== "boolean") {
    return null;
  }
  const allowed = normalizeHosts(value.allowed_hosts);
  if (allowed === null) {
    return null;
  }
  const rate = normalizeRate(value.rate_limit);
  if (rate === null) {
    return null;
  }
  return {
    disabled: value.disabled === true,
    record: {
      name: value.name,
      owner: value.owner,
      scopes,
      allowed_hosts: allowed,
      rate_limit: rate,
    },
  };
}

function normalizeHosts(value: unknown): string[] | null {
  if (value === undefined) {
    return [];
  }
  if (!Array.isArray(value)) {
    return null;
  }
  const hosts: string[] = [];
  for (const entry of value) {
    if (typeof entry !== "string") {
      return null;
    }
    const host = entry.trim().toLowerCase().replace(/\.$/, "");
    if (host === "" || host.includes("/") || host.includes("*") || host.includes(" ") || !/^[a-z0-9.-]+$/.test(host)) {
      return null;
    }
    hosts.push(host);
  }
  return hosts;
}

function normalizeRate(value: unknown): RateLimit | null {
  if (value === undefined) {
    return DEFAULT_RATE;
  }
  if (!isRecord(value)) {
    return null;
  }
  const limit = value.limit === undefined ? DEFAULT_RATE.limit : value.limit;
  const windowSeconds = value.window_seconds === undefined ? DEFAULT_RATE.window_seconds : value.window_seconds;
  if (typeof limit !== "number" || !Number.isInteger(limit) || limit < 1 || limit > 10000) {
    return null;
  }
  if (typeof windowSeconds !== "number" || !Number.isInteger(windowSeconds) || windowSeconds < 60 || windowSeconds > 86400) {
    return null;
  }
  return { limit, window_seconds: windowSeconds };
}
