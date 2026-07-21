import {
  deriveCrudModel,
  effectiveCapabilities,
  humanizeKey,
  resolveRef,
  type CrudCapabilities,
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
});

describe('effectiveCapabilities', () => {
  const ALL_TRUE: CrudCapabilities = {
    canCreate: true,
    canEdit: true,
    canDelete: true,
  };
  const ALL_FALSE: CrudCapabilities = {
    canCreate: false,
    canEdit: false,
    canDelete: false,
  };

  it('ANDs both sides per field — both true yields all true', () => {
    expect(effectiveCapabilities(ALL_TRUE, ALL_TRUE)).toEqual(ALL_TRUE);
  });

  it('yields false on a field when the spec side is false', () => {
    const spec: CrudCapabilities = {
      canCreate: false,
      canEdit: true,
      canDelete: true,
    };
    expect(effectiveCapabilities(spec, ALL_TRUE)).toEqual({
      canCreate: false,
      canEdit: true,
      canDelete: true,
    });
  });

  it('yields false on a field when the caller side is false', () => {
    const caller: CrudCapabilities = {
      canCreate: true,
      canEdit: false,
      canDelete: false,
    };
    expect(effectiveCapabilities(ALL_TRUE, caller)).toEqual({
      canCreate: true,
      canEdit: false,
      canDelete: false,
    });
  });

  it('treats a null/undefined spec side as all-false', () => {
    expect(effectiveCapabilities(null, ALL_TRUE)).toEqual(ALL_FALSE);
    expect(effectiveCapabilities(undefined, ALL_TRUE)).toEqual(ALL_FALSE);
  });

  it('treats a null/undefined caller side as all-false', () => {
    expect(effectiveCapabilities(ALL_TRUE, null)).toEqual(ALL_FALSE);
    expect(effectiveCapabilities(ALL_TRUE, undefined)).toEqual(ALL_FALSE);
  });

  it('yields all-false when both sides are absent', () => {
    expect(effectiveCapabilities(null, undefined)).toEqual(ALL_FALSE);
  });
});

describe('deriveCrudModel — dangling refs', () => {
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

describe('deriveCrudModel — LocalizedText convention (WC-532)', () => {
  function specWithStem(stemSchema: Record<string, unknown>): OpenApiSpec {
    return {
      paths: {
        '/api/questions': {
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
                              stem: stemSchema,
                              plainNote: { type: 'object', properties: { x: { type: 'string' } } },
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
          post: {
            requestBody: {
              content: {
                'application/json': {
                  schema: {
                    type: 'object',
                    required: ['stem'],
                    properties: { stem: stemSchema },
                  },
                },
              },
            },
          },
        },
      },
    };
  }

  const STEM_SCHEMA = {
    type: 'object',
    'x-whity-localized-text': true,
    properties: { ar: { type: 'string' }, en: { type: 'string' } },
  };

  it('surfaces a marked object property as a "localized-text" column, unlike a plain object', () => {
    const model = deriveCrudModel(specWithStem(STEM_SCHEMA), '/api/questions');

    expect(model.columns).toEqual([
      { key: 'id', label: 'ID' },
      { key: 'stem', label: 'Stem', isLocalizedText: true },
      // plainNote (an unmarked object) is skipped, exactly as before this convention existed.
    ]);
  });

  it('does NOT mark an unmarked object property, even with the same {ar,en}-shaped properties', () => {
    const unmarked = {
      type: 'object',
      properties: { ar: { type: 'string' }, en: { type: 'string' } },
    };
    const model = deriveCrudModel(specWithStem(unmarked), '/api/questions');

    // Without the x-whity-localized-text marker, "stem" is a plain object and is skipped.
    expect(model.columns).toEqual([{ key: 'id', label: 'ID' }]);
  });

  it('derives a "localized-text" create-form field, required flag preserved', () => {
    const model = deriveCrudModel(specWithStem(STEM_SCHEMA), '/api/questions');

    expect(model.createFields).toEqual([
      { name: 'stem', label: 'Stem', kind: 'localized-text', required: true },
    ]);
  });
});
