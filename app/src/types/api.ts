// API Platform serves JSON:API at `application/vnd.api+json`. We flatten each
// resource at the data-layer boundary (lib/api/server.ts) so consumers see a
// flat shape: `id` is the UUID (extracted from the API's `attributes._id`),
// `type` is the JSON:API resource type, attributes are spread on top, and
// to-one/to-many relationships are flattened to UUID strings. The IRIs the API
// uses internally are reconstructed on write inside lib/api/actions.ts and are
// not exposed to consumers.
export interface ApiResource {
  id: string;
  type: string;
}

export interface JsonApiCollection<T> {
  data: T[];
  totalItems: number;
}

export interface JsonApiErrorSource {
  pointer?: string;
  parameter?: string;
}

export interface JsonApiError {
  status?: string;
  code?: string;
  title?: string;
  detail?: string;
  source?: JsonApiErrorSource;
}
