/**
 * Pure OpenAPI → CRUD-model derivation for plugin feature screens (WC-169).
 *
 * The generic CRUD renderer feeds the backend's static `/openapi.json` spec
 * and a feature's `resource.basePath` into {@link deriveCrudModel} and gets
 * back everything it needs to render a list + create/edit/delete UI:
 * table columns, form fields and operation capabilities.
 *
 * Only the minimal OpenAPI 3.x subset actually consumed is modelled — paths
 * with get/post/patch/delete operations, JSON request/response schemas
 * (inline or `#/components/schemas/*` refs) and primitive property facets
 * (type/required/nullable/enum/maxLength/minLength). Everything is defensive:
 * missing operations or schemas yield empty fields / false capabilities,
 * never a throw.
 */

/** A JSON-schema node — either a `$ref` pointer or an inline schema. */
export interface SchemaObject {
  $ref?: string;
  type?: string;
  properties?: Record<string, SchemaObject>;
  required?: string[];
  items?: SchemaObject;
  nullable?: boolean;
  enum?: ReadonlyArray<string | number>;
  maxLength?: number;
  minLength?: number;
  format?: string;
}

export interface MediaTypeObject {
  schema?: SchemaObject;
}

export interface RequestBodyObject {
  required?: boolean;
  content?: Record<string, MediaTypeObject>;
}

export interface ResponseObject {
  description?: string;
  content?: Record<string, MediaTypeObject>;
}

export interface OperationObject {
  requestBody?: RequestBodyObject;
  responses?: Record<string, ResponseObject>;
}

export interface PathItemObject {
  get?: OperationObject;
  post?: OperationObject;
  patch?: OperationObject;
  delete?: OperationObject;
}

export interface OpenApiSpec {
  paths?: Record<string, PathItemObject>;
  components?: {
    schemas?: Record<string, SchemaObject>;
  };
}

/** Input control kinds the generic form knows how to render. */
export type CrudFieldKind = 'text' | 'textarea' | 'number' | 'checkbox' | 'select';

/** A single create/edit form field derived from a request-body schema. */
export interface CrudField {
  name: string;
  label: string;
  kind: CrudFieldKind;
  required: boolean;
  /** Present for `kind: "select"` — the enum values as strings. */
  options?: string[];
  /** Present when the schema declares a maxLength constraint. */
  maxLength?: number;
}

/** A list-table column derived from the item schema's primitive properties. */
export interface CrudColumn {
  key: string;
  label: string;
}

/** Which mutations the spec actually publishes for the resource. */
export interface CrudCapabilities {
  canCreate: boolean;
  canEdit: boolean;
  canDelete: boolean;
}

/**
 * AND two capability sets per field (WC-175, issue #199).
 *
 * The renderer must only show a write control when the spec defines the
 * operation (`spec`, from {@link deriveCrudModel}) AND the server says the
 * caller may perform it (`caller`, from `feature.capabilities`) — otherwise a
 * submit would 403. A null/undefined side is treated as all-false, so a missing
 * spec (failed schema load) or absent server capabilities degrades to no
 * controls rather than a crash.
 */
export function effectiveCapabilities(
  spec: CrudCapabilities | null | undefined,
  caller: CrudCapabilities | null | undefined
): CrudCapabilities {
  return {
    canCreate: (spec?.canCreate ?? false) && (caller?.canCreate ?? false),
    canEdit: (spec?.canEdit ?? false) && (caller?.canEdit ?? false),
    canDelete: (spec?.canDelete ?? false) && (caller?.canDelete ?? false),
  };
}

export interface CrudModel {
  /** The resolved list-item schema, or null when it cannot be located. */
  itemSchema: SchemaObject | null;
  columns: CrudColumn[];
  createFields: CrudField[];
  editFields: CrudField[];
  capabilities: CrudCapabilities;
}

const SCHEMA_REF_PREFIX = '#/components/schemas/';

/**
 * Resolve a `#/components/schemas/X` pointer against the spec.
 * Any other pointer shape (or an unknown name) resolves to undefined.
 */
export function resolveRef(
  spec: OpenApiSpec,
  ref: string
): SchemaObject | undefined {
  if (!ref.startsWith(SCHEMA_REF_PREFIX)) {
    return undefined;
  }
  const name = ref.slice(SCHEMA_REF_PREFIX.length);
  return spec.components?.schemas?.[name];
}

/** Follow a node's `$ref` (if any) to its concrete schema. */
function deref(
  spec: OpenApiSpec,
  schema: SchemaObject | undefined
): SchemaObject | undefined {
  if (schema === undefined) {
    return undefined;
  }
  if (schema.$ref !== undefined) {
    return resolveRef(spec, schema.$ref);
  }
  return schema;
}

/**
 * Humanize a property key into a column/field label:
 * camelCase and snake_case/kebab-case split into Title Case words, with the
 * word "id" rendered as "ID" (`tenant_id` → "Tenant ID", `id` → "ID").
 */
export function humanizeKey(key: string): string {
  return key
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
    .split(/[-_\s]+/)
    .filter((word) => word.length > 0)
    .map((word) =>
      word.toLowerCase() === 'id'
        ? 'ID'
        : word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()
    )
    .join(' ');
}

/** Extract the dereferenced application/json schema from a content map. */
function jsonSchema(
  spec: OpenApiSpec,
  content: Record<string, MediaTypeObject> | undefined
): SchemaObject | undefined {
  return deref(spec, content?.['application/json']?.schema);
}

/**
 * Map a resolved property schema to a form-field kind.
 * Returns null for shapes the generic form cannot render (objects, arrays,
 * untyped nodes without an enum) — those properties are skipped.
 *
 * Textarea rule (documented + tested): a string renders as a multi-line
 * textarea only when it declares `maxLength > 120`; strings with
 * `maxLength <= 120` or with NO maxLength render as single-line text inputs.
 * Rationale: an explicitly large bound signals long-form content, while
 * unconstrained strings are most often short identity-ish values (names,
 * emails, slugs) best served by a single-line input.
 */
function fieldKindOf(prop: SchemaObject): CrudFieldKind | null {
  if (prop.enum !== undefined && prop.enum.length > 0) {
    return 'select';
  }
  switch (prop.type) {
    case 'boolean':
      return 'checkbox';
    case 'integer':
    case 'number':
      return 'number';
    case 'string':
      return prop.maxLength !== undefined && prop.maxLength > 120
        ? 'textarea'
        : 'text';
    default:
      return null;
  }
}

/** Whether a resolved property is a table-renderable primitive. */
function isPrimitive(prop: SchemaObject): boolean {
  if (prop.enum !== undefined && prop.enum.length > 0) {
    return true;
  }
  return (
    prop.type === 'string' ||
    prop.type === 'integer' ||
    prop.type === 'number' ||
    prop.type === 'boolean'
  );
}

/** Derive form fields from an operation's JSON request body, if present. */
function fieldsFromOperation(
  spec: OpenApiSpec,
  operation: OperationObject | undefined
): CrudField[] {
  const schema = jsonSchema(spec, operation?.requestBody?.content);
  const properties = schema?.properties;
  if (properties === undefined) {
    return [];
  }
  const required = schema?.required ?? [];

  const fields: CrudField[] = [];
  for (const [name, raw] of Object.entries(properties)) {
    const prop = deref(spec, raw);
    if (prop === undefined) {
      continue;
    }
    const kind = fieldKindOf(prop);
    if (kind === null) {
      continue;
    }
    const field: CrudField = {
      name,
      label: humanizeKey(name),
      kind,
      required: required.includes(name),
    };
    if (kind === 'select' && prop.enum !== undefined) {
      field.options = prop.enum.map((value) => String(value));
    }
    if (prop.maxLength !== undefined) {
      field.maxLength = prop.maxLength;
    }
    fields.push(field);
  }
  return fields;
}

/** Locate the list response's `data[]` item schema (GET basePath → 200). */
function findItemSchema(
  spec: OpenApiSpec,
  basePath: string
): SchemaObject | null {
  const listOperation = spec.paths?.[basePath]?.get;
  const responseSchema = jsonSchema(
    spec,
    listOperation?.responses?.['200']?.content
  );
  const dataProp = deref(spec, responseSchema?.properties?.['data']);
  return deref(spec, dataProp?.items) ?? null;
}

/** Derive table columns from the item schema's primitive properties. */
function columnsFrom(
  spec: OpenApiSpec,
  itemSchema: SchemaObject | null
): CrudColumn[] {
  const properties = itemSchema?.properties;
  if (properties === undefined) {
    return [];
  }

  const columns: CrudColumn[] = [];
  for (const [key, raw] of Object.entries(properties)) {
    const prop = deref(spec, raw);
    if (prop === undefined || !isPrimitive(prop)) {
      continue;
    }
    columns.push({ key, label: humanizeKey(key) });
  }

  // `id` always leads when present, regardless of declaration order.
  const idIndex = columns.findIndex((column) => column.key === 'id');
  if (idIndex > 0) {
    const [idColumn] = columns.splice(idIndex, 1);
    columns.unshift(idColumn);
  }
  return columns;
}

/**
 * Derive the full CRUD model for a resource published at `basePath`.
 *
 * - itemSchema/columns follow `GET basePath → 200 → { data: Item[] }`.
 * - createFields/editFields follow the POST (collection) and PATCH (item)
 *   JSON request bodies.
 * - capabilities reflect operation presence: POST on `basePath`, PATCH and
 *   DELETE on `${basePath}/{id}`.
 *
 * Defensive by design: anything missing degrades to empty fields / false
 * capabilities — this function never throws on sparse or empty specs.
 */
export function deriveCrudModel(
  spec: OpenApiSpec,
  basePath: string
): CrudModel {
  const collection = spec.paths?.[basePath];
  const item = spec.paths?.[`${basePath}/{id}`];

  const itemSchema = findItemSchema(spec, basePath);

  return {
    itemSchema,
    columns: columnsFrom(spec, itemSchema),
    createFields: fieldsFromOperation(spec, collection?.post),
    editFields: fieldsFromOperation(spec, item?.patch),
    capabilities: {
      canCreate: collection?.post !== undefined,
      canEdit: item?.patch !== undefined,
      canDelete: item?.delete !== undefined,
    },
  };
}
