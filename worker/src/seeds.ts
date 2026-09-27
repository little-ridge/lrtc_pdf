import index from "../../templates/index.json";
import body from "../../templates/greeting/body.html";
import header from "../../templates/greeting/header.html";
import footer from "../../templates/greeting/footer.html";
import meta from "../../templates/greeting/meta.json";
import { parseManifest, type FieldManifest } from "./fields";

export interface TemplateDocument {
  id: string;
  html: string;
  header_html: string | null;
  footer_html: string | null;
  paper: string;
  default_font: string;
  fields: FieldManifest | null;
  sample: Record<string, unknown> | null;
  updated: string | null;
  seed: boolean;
}

const parsed = parseManifest(meta.fields);
const greeting: TemplateDocument = {
  id: "greeting",
  html: body,
  header_html: header,
  footer_html: footer,
  paper: meta.paper,
  default_font: meta.default_font,
  fields: parsed.ok ? parsed.manifest : null,
  sample: meta.sample,
  updated: null,
  seed: true,
};

const byId: Record<string, TemplateDocument> = {
  greeting,
};

export function seedDocument(id: string): TemplateDocument | null {
  if (!index.includes(id)) {
    return null;
  }
  return byId[id] ?? null;
}

export function seedDocuments(): TemplateDocument[] {
  return index.flatMap((id) => {
    const document = byId[id];
    return document ? [document] : [];
  });
}
