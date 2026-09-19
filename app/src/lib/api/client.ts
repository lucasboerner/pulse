import { redirect } from "next/navigation";
import type { ApiResource } from "@/types/api";
import { getToken } from "@/lib/auth";

// Server Components run inside the `app` container and reach the API over the
// internal Docker network hostname, not the browser-facing domain.
const INTERNAL_API_URL = process.env.API_INTERNAL_URL ?? "http://api";

// `application/vnd.api+json` is the only structured format the backend
// advertises (alongside plain `application/json`) — JSON-LD/Hydra is disabled in
// api/config/packages/api_platform.yaml. API Platform emits the JSON:API
// document shape parsed by `flattenResource` / `flattenCollection` below.
export const JSON_API_MEDIA_TYPE = "application/vnd.api+json";

// Mirrors the backend's `paginationItemsPerPage` so total-page math stays stable
// even if a response omits `meta.itemsPerPage`.
const DEFAULT_PAGE_SIZE = 20;

interface JsonApiRelationshipRef {
  type: string;
  // API Platform encodes the related resource's IRI here (e.g. /api/examples/<uuid>).
  id: string;
}

interface JsonApiRelationship {
  data: JsonApiRelationshipRef | JsonApiRelationshipRef[] | null;
}

interface JsonApiResource {
  // API Platform uses the resource IRI as the JSON:API `id`. The UUID is
  // mirrored to `attributes._id` so consumers can keep using it for routing.
  id: string;
  type: string;
  attributes?: Record<string, unknown>;
  relationships?: Record<string, JsonApiRelationship>;
}

interface JsonApiCollectionDocument {
  data: JsonApiResource[];
  // JSON:API compound-document side-load: related resources pulled in via
  // `?include=`. Each appears once even when shared across several `data` rows.
  included?: JsonApiResource[];
  meta?: { totalItems?: number; itemsPerPage?: number; currentPage?: number };
  links?: { self?: string; first?: string; last?: string; next?: string; prev?: string };
}

interface JsonApiResourceDocument {
  data: JsonApiResource;
  included?: JsonApiResource[];
}

// One slice of a paginated collection plus the totals a list view needs to
// render its header count and pagination control.
export interface CollectionPage<T> {
  data: T[];
  totalItems: number;
  itemsPerPage: number;
  page: number;
}

export interface ApiFetchOptions {
  // When true, a 404 should render the nearest not-found.tsx. The caller decides
  // how — this flag only stops `apiFetch` from throwing.
  notFoundOn404?: boolean;
  // Opts the request into the Next Data Cache under these tags. Only for data
  // every user sees identically: a cache entry may be shared across sessions.
  // Operational reads must stay on the default `no-store`.
  tags?: string[];
  revalidate?: number;
  // Extra headers — e.g. an Authorization bearer once you add authentication.
  headers?: Record<string, string>;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/**
 * Raw fetch against the API. Returns the parsed JSON:API document; the helpers
 * below flatten it. Throws `ApiError` on any non-OK response so a Server
 * Component's error boundary (error.tsx) can render it.
 */
export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  // The bearer token from the httpOnly cookie is attached here, in the one place
  // that talks to the API. A caller may still override it via options.headers.
  const token = await getToken();
  const response = await fetch(`${INTERNAL_API_URL}${path}`, {
    headers: {
      Accept: JSON_API_MEDIA_TYPE,
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
    ...(options.tags
      ? { next: { tags: options.tags, revalidate: options.revalidate } }
      : { cache: "no-store" as const }),
  });

  if (response.status === 401) {
    // Central 401 handling: the token is missing, expired or rejected. Bounce
    // through /logout, which clears the cookie before landing on /login — a
    // straight redirect to /login would loop past the middleware gate, which
    // only judges the token structurally.
    redirect("/logout");
  }

  if (!response.ok) {
    throw new ApiError(`API request to ${path} failed with status ${response.status}`, response.status);
  }

  return response.json() as Promise<T>;
}

// API Platform's `data.id` is an IRI like `/api/examples/<uuid>`; consumers only
// ever want the UUID. The IRI ↔ UUID round-trip is the data layer's job.
export function uuidFromIri(iri: string): string {
  return iri.split("/").pop() ?? iri;
}

/**
 * Collapse a JSON:API resource into the flat shape the rest of the codebase
 * consumes: `id` (UUID) from `attributes._id`, `type`, all attributes spread on
 * top, and relationships flattened to UUID strings (or arrays of UUIDs).
 */
export function flattenResource<T extends ApiResource>(resource: JsonApiResource): T {
  const { id: iri, type, attributes = {}, relationships = {} } = resource;
  const { _id, ...attrs } = attributes as Record<string, unknown> & { _id?: string };

  const flatRelationships: Record<string, string | string[] | null> = {};
  for (const [key, value] of Object.entries(relationships)) {
    const data = value?.data ?? null;
    if (Array.isArray(data)) {
      flatRelationships[key] = data.map((ref) => uuidFromIri(ref.id));
    } else if (data) {
      flatRelationships[key] = uuidFromIri(data.id);
    } else {
      flatRelationships[key] = null;
    }
  }

  return {
    id: typeof _id === "string" ? _id : uuidFromIri(iri),
    type,
    ...attrs,
    ...flatRelationships,
  } as T;
}

/** Fetches one resource by path and flattens it. */
export async function fetchResource<T extends ApiResource>(
  path: string,
  options: ApiFetchOptions = {},
): Promise<T> {
  const doc = await apiFetch<JsonApiResourceDocument>(path, options);
  return flattenResource<T>(doc.data);
}

/**
 * Fetches a single page of a JSON:API collection. `params` carries any extra
 * query the backend understands (search term, filters, …); blank entries are
 * dropped. The caller maps/normalizes the rows.
 */
export async function fetchPage<T extends ApiResource>(
  basePath: string,
  page = 1,
  params: Record<string, string | undefined> = {},
  options: ApiFetchOptions = {},
): Promise<CollectionPage<T>> {
  const search = new URLSearchParams({ page: String(Math.max(1, page)) });
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") search.set(key, value);
  }

  const doc = await apiFetch<JsonApiCollectionDocument>(`${basePath}?${search.toString()}`, options);
  const items = doc.data.map((resource) => flattenResource<T>(resource));

  return {
    data: items,
    totalItems: doc.meta?.totalItems ?? items.length,
    itemsPerPage: doc.meta?.itemsPerPage ?? DEFAULT_PAGE_SIZE,
    page: doc.meta?.currentPage ?? page,
  };
}

/**
 * Pulls a whole reference collection for client-side filtering/sorting. The
 * backend allows up to `cap` rows per page (pagination_maximum_items_per_page),
 * so a single request usually returns everything. The `links.next` loop stays as
 * a defensive fallback for any resource that hands back a smaller page, and it
 * keeps the cap honored.
 */
export async function fetchAll<T extends ApiResource>(
  basePath: string,
  cap = 2000,
  options: ApiFetchOptions = {},
): Promise<{ data: T[]; totalItems: number }> {
  const sep = basePath.includes("?") ? "&" : "?";
  let next: string | undefined = `${basePath}${sep}page=1&itemsPerPage=${cap}`;
  const all: T[] = [];
  let totalItems = 0;

  while (next && all.length < cap) {
    const doc: JsonApiCollectionDocument = await apiFetch<JsonApiCollectionDocument>(next, options);
    totalItems = doc.meta?.totalItems ?? totalItems;
    all.push(...doc.data.map((resource) => flattenResource<T>(resource)));
    next = doc.links?.next;
  }

  return { data: all, totalItems: totalItems || all.length };
}
