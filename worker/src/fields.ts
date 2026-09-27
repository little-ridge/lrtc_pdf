import { isRecord } from "./http";

export type FieldType = "string" | "html" | "paragraphs" | "list";

export interface FieldSpec {
  type: FieldType;
  required: boolean;
}

export type FieldManifest = Record<string, FieldSpec>;

const FIELD_TYPES: readonly FieldType[] = ["string", "html", "paragraphs", "list"];

export function parseManifest(value: unknown): { ok: true; manifest: FieldManifest | null } | { ok: false } {
  if (value === undefined || value === null) {
    return { ok: true, manifest: null };
  }
  if (!isRecord(value)) {
    return { ok: false };
  }
  const manifest: FieldManifest = {};
  for (const [name, spec] of Object.entries(value)) {
    if (!/^[A-Za-z0-9_]{1,64}$/.test(name) || !isRecord(spec)) {
      return { ok: false };
    }
    const type = spec.type;
    if (typeof type !== "string" || !FIELD_TYPES.includes(type as FieldType)) {
      return { ok: false };
    }
    const extra = Object.keys(spec).filter((key) => key !== "type" && key !== "required");
    if (extra.length > 0) {
      return { ok: false };
    }
    if (spec.required !== undefined && typeof spec.required !== "boolean") {
      return { ok: false };
    }
    manifest[name] = { type: type as FieldType, required: spec.required === true };
  }
  return { ok: true, manifest };
}

export function validateData(
  manifest: FieldManifest | null,
  data: Record<string, unknown>,
): { missing: string[]; invalid: { field: string; message: string }[] } | null {
  if (manifest === null) {
    return null;
  }
  const missing: string[] = [];
  const invalid: { field: string; message: string }[] = [];
  for (const [name, spec] of Object.entries(manifest)) {
    const present = Object.prototype.hasOwnProperty.call(data, name) && data[name] !== null;
    if (!present) {
      if (spec.required) {
        missing.push(name);
      }
      continue;
    }
    const message = typeError(spec.type, data[name]);
    if (message !== null) {
      invalid.push({ field: name, message });
    }
  }
  if (missing.length === 0 && invalid.length === 0) {
    return null;
  }
  return { missing, invalid };
}

function typeError(type: FieldType, value: unknown): string | null {
  if (type === "string" || type === "html") {
    return typeof value === "string" ? null : "expected a string";
  }
  if (type === "list") {
    return Array.isArray(value) ? null : "expected a list";
  }
  if (typeof value === "string") {
    return null;
  }
  if (!isRecord(value)) {
    return "expected a string or a paragraphs object";
  }
  if (typeof value.paragraphs !== "string") {
    return "paragraphs must be a string";
  }
  const extra = Object.keys(value).filter((key) => key !== "paragraphs" && key !== "td_style");
  if (extra.length > 0) {
    return "paragraphs objects only allow paragraphs and td_style";
  }
  if (value.td_style !== undefined && typeof value.td_style !== "string") {
    return "td_style must be a string";
  }
  return null;
}
