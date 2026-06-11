import {
  deriveCrudModel,
  humanizeKey,
  resolveRef,
  type OpenApiSpec,
} from '@/lib/plugin-crud-schema';

/**
 * Hand-written OpenAPI fragment mirroring the HelloWorld reference plugin
 * (basePath /api/hello/greetings) plus extra property shapes to exercise the
 * full derivation matrix: enum (select), boolean (checkbox), integer/number
 * (number), short/long/unbounded strings (text vs textarea) and nested
 * object/array properties that must be skipped.
 */
const SPEC: OpenApiSpec = {
  paths: {
    '/api/hello/greetings': {
      get: {
        responses: {
          '200': {
            content: {
              'application/json': {
                schema: {
                  type: 'object',
                  properties: {
                    data: {
                      type: 'array',
                      items: { $ref: '#/components/schemas/HelloGreeting' },
                    },
                  },
                },
              },
            },
          },
        },
      },
      post: {
        requestBody: {
          content: {
            'application/json': {
              schema: { $ref: '#/components/schemas/HelloGreetingCreate' },
            },
          },
        },
        responses: {
          '201': {
            content: {
              'application/json': {
                schema: { $ref: '#/components/schemas/HelloGreeting' },
              },
            },
          },
        },
      },
    },
    '/api/hello/greetings/{id}': {
      patch: {
        requestBody: {
          content: {
            'application/json': {
              schema: { $ref: '#/components/schemas/HelloGreetingUpdate' },
            },
          },
        },
        responses: {
          '200': {
            content: {
              'application/json': {
                schema: { $ref: '#/components/schemas/HelloGreeting' },
              },
            },
          },
        },
      },
      delete: {
        responses: { '200': { description: 'Deleted' } },
      },
    },
  },
  components: {
    schemas: {
      HelloGreeting: {
        type: 'object',
        properties: {
          // `id` is intentionally NOT first in declaration order — the model
          // must still surface it as the first column.
          message: { type: 'string', maxLength: 255 },
          id: { type: 'integer' },
          priority: { type: 'string', enum: ['low', 'high'] },
          urgent: { type: 'boolean' },
          weight: { type: 'number' },
          meta: { type: 'object', properties: { foo: { type: 'string' } } },
          tags: { type: 'array', items: { type: 'string' } },
          createdAt: { type: 'string' },
        },
      },
      HelloGreetingCreate: {
        type: 'object',
        required: ['message'],
        properties: {
          message: { type: 'string', maxLength: 255 },
          subject: { type: 'string', maxLength: 80 },
          note: { type: 'string' },
          priority: { type: 'string', enum: ['low', 'high'] },
          urgent: { type: 'boolean' },
          count: { type: 'integer' },
          meta: { type: 'object' },
        },
      },
      HelloGreetingUpdate: {
        type: 'object',
        properties: {
          message: { type: 'string', maxLength: 255 },
        },
      },
    },
  },
};

describe('resolveRef', () => {
  it('resolves a #/components/schemas/* pointer', () => {
    const schema = resolveRef(SPEC, '#/components/schemas/HelloGreeting');
    expect(schema).toBeDefined();
    expect(schema?.properties?.message).toEqual({
      type: 'string',
      maxLength: 255,
    });
  });

  it('returns undefined for an unknown schema name', () => {
    expect(resolveRef(SPEC, '#/components/schemas/Nope')).toBeUndefined();
  });

  it('returns undefined for a non-schema pointer', () => {
    expect(resolveRef(SPEC, '#/components/parameters/Page')).toBeUndefined();
  });
});

describe('humanizeKey', () => {
  it('title-cases camelCase keys', () => {
    expect(humanizeKey('createdAt')).toBe('Created At');
  });

  it('title-cases snake_case keys', () => {
    expect(humanizeKey('tenant_id')).toBe('Tenant ID');
  });

  it('upper-cases bare id', () => {
    expect(humanizeKey('id')).toBe('ID');
  });

  it('capitalizes single words', () => {
    expect(humanizeKey('name')).toBe('Name');
  });
});

describe('deriveCrudModel', () => {
  const model = deriveCrudModel(SPEC, '/api/hello/greetings');

  it('resolves the list item schema through $ref', () => {
    expect(model.itemSchema).not.toBeNull();
    expect(model.itemSchema?.properties?.message).toEqual({
      type: 'string',
      maxLength: 255,
    });
  });

  it('derives columns from primitives, id first, skipping objects and arrays', () => {
    expect(model.columns).toEqual([
      { key: 'id', label: 'ID' },
      { key: 'message', label: 'Message' },
      { key: 'priority', label: 'Priority' },
      { key: 'urgent', label: 'Urgent' },
      { key: 'weight', label: 'Weight' },
      { key: 'createdAt', label: 'Created At' },
    ]);
  });

  it('derives create fields with kinds, required flags, options and maxLength', () => {
    expect(model.createFields).toEqual([
      // maxLength 255 > 120 → textarea
      {
        name: 'message',
        label: 'Message',
        kind: 'textarea',
        required: true,
        maxLength: 255,
      },
      // maxLength 80 <= 120 → single-line text
      {
        name: 'subject',
        label: 'Subject',
        kind: 'text',
        required: false,
        maxLength: 80,
      },
      // no maxLength → single-line text (unconstrained strings default short)
      { name: 'note', label: 'Note', kind: 'text', required: false },
      {
        name: 'priority',
        label: 'Priority',
        kind: 'select',
        required: false,
        options: ['low', 'high'],
      },
      { name: 'urgent', label: 'Urgent', kind: 'checkbox', required: false },
      { name: 'count', label: 'Count', kind: 'number', required: false },
      // `meta` (object) is skipped entirely
    ]);
  });

  it('derives edit fields from the PATCH request body', () => {
    expect(model.editFields).toEqual([
      {
        name: 'message',
        label: 'Message',
        kind: 'textarea',
        required: false,
        maxLength: 255,
      },
    ]);
  });

  it('reports full capabilities when POST/PATCH/DELETE operations exist', () => {
    expect(model.capabilities).toEqual({
      canCreate: true,
      canEdit: true,
      canDelete: true,
    });
  });

  it('supports a fully inline item schema (no $ref)', () => {
    const inlineSpec: OpenApiSpec = {
      paths: {
        '/api/things': {
          get: {
            responses: {
              '200': {
                content: {
                  'application/json': {
                    schema: {
                      type: 'object',
                      properties: {
                        data: {
                          type: 'array',
                          items: {
                            type: 'object',
                            properties: {
                              id: { type: 'integer' },
                              name: { type: 'string' },
                            },
                          },
                        },
                      },
                    },
                  },
                },
              },
            },
          },
        },
      },
    };

    const inlineModel = deriveCrudModel(inlineSpec, '/api/things');
    expect(inlineModel.columns).toEqual([
      { key: 'id', label: 'ID' },
      { key: 'name', label: 'Name' },
    ]);
  });

  it('keeps declaration order when the item schema has no id property', () => {
    const noIdSpec: OpenApiSpec = {
      paths: {
        '/api/things': {
          get: {
            responses: {
              '200': {
                content: {
                  'application/json': {
                    schema: {
                      type: 'object',
                      properties: {
                        data: {
                          type: 'array',
                          items: {
                            type: 'object',
                            properties: {
                              name: { type: 'string' },
                              size: { type: 'integer' },
                            },
                          },
                        },
                      },
                    },
                  },
                },
              },
            },
          },
        },
      },
    };

    expect(deriveCrudModel(noIdSpec, '/api/things').columns).toEqual([
      { key: 'name', label: 'Name' },
      { key: 'size', label: 'Size' },
    ]);
  });

  it('reports canEdit/canDelete false and empty editFields when the item path is absent', () => {
    const listOnly: OpenApiSpec = {
      paths: {
        '/api/hello/greetings': SPEC.paths!['/api/hello/greetings'],
      },
      components: SPEC.components,
    };

    const m = deriveCrudModel(listOnly, '/api/hello/greetings');
    expect(m.capabilities).toEqual({
      canCreate: true,
      canEdit: false,
      canDelete: false,
    });
    expect(m.editFields).toEqual([]);
  });

  it('never throws on an empty spec — returns an inert model', () => {
    const m = deriveCrudModel({}, '/api/hello/greetings');
    expect(m.itemSchema).toBeNull();
    expect(m.columns).toEqual([]);
    expect(m.createFields).toEqual([]);
    expect(m.editFields).toEqual([]);
    expect(m.capabilities).toEqual({
      canCreate: false,
      canEdit: false,
      canDelete: false,
    });
  });

  it('never throws when the response/requestBody schemas are missing', () => {
    const sparse: OpenApiSpec = {
      paths: {
        '/api/x': { get: {}, post: {} },
        '/api/x/{id}': { patch: {}, delete: {} },
      },
    };

    const m = deriveCrudModel(sparse, '/api/x');
    expect(m.itemSchema).toBeNull();
    expect(m.columns).toEqual([]);
    expect(m.createFields).toEqual([]);
    expect(m.editFields).toEqual([]);
    // Operation presence still drives capabilities, even without schemas.
    expect(m.capabilities).toEqual({
      canCreate: true,
      canEdit: true,
      canDelete: true,
    });
  });

  it('skips properties whose $ref cannot be resolved', () => {
    const danglingRef: OpenApiSpec = {
      paths: {
        '/api/things': {
          get: {
            responses: {
              '200': {
                content: {
                  'application/json': {
                    schema: {
                      type: 'object',
                      properties: {
                        data: {
                          type: 'array',
                          items: {
                            type: 'object',
                            properties: {
                              id: { type: 'integer' },
                              broken: { $ref: '#/components/schemas/Gone' },
                            },
                          },
                        },
                      },
                    },
                  },
                },
              },
            },
          },
        },
      },
    };

    expect(deriveCrudModel(danglingRef, '/api/things').columns).toEqual([
      { key: 'id', label: 'ID' },
    ]);
  });
});
