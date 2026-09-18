# Data & forms

The app is **fully server-rendered**. The browser makes no API calls. Reads happen
in Server Components via `src/lib/api/client.ts`; writes happen via Server Actions in
`src/lib/api/actions.ts`.

Do not add client-side fetching (no React Query, no SWR, no `useEffect` + `fetch`,
no BFF proxy route handlers). This keeps the API credential (once auth exists) in
an httpOnly cookie that JS never touches, and keeps JSON:API unwrapping in exactly
one place.

## How a request flows

```
Browser → Next.js (Node, app container) → Symfony API (internal Docker hostname)
            │                                          │
            ├─ reads:  Server Components               ├─ Accept: application/vnd.api+json
            │           call lib/api/client.ts         │
            └─ writes: client form calls a             │
                        Server Action in actions.ts    │
```

The `app` container reaches the API over the internal Docker hostname (`http://api`),
not the public domain. This is set by `API_INTERNAL_URL`, defaulted in code to
`http://api`.

## Reading — `src/lib/api/client.ts`

Three read shapes:

- **One page**: `fetchPage<T>(basePath, page, params)` → `CollectionPage<T>` with
  `data`, `totalItems`, `itemsPerPage`, `page`. Use for list views with server-side
  pagination.
- **Everything**: `fetchAll<T>(basePath, cap = 2000)` → the whole collection. The
  backend allows up to `pagination_maximum_items_per_page` rows per request, so this
  is usually a single round trip; the `links.next` loop is a defensive fallback. Use
  for reference dropdowns and client-side filtering — never fetch only `?page=1` for
  one of those, it silently truncates.
- **One resource**: `fetchResource<T>(path)`.

All three go through `apiFetch`, which sets `Accept: application/vnd.api+json` and
throws `ApiError` on any non-OK response (caught by `error.tsx`).

### JSON:API, not JSON-LD

The backend serves **JSON:API**; JSON-LD/Hydra is disabled. `flattenResource`
collapses each `{ id, type, attributes, relationships }` document into the flat shape
the rest of the app consumes:

- `id` is the UUID (API Platform mirrors it into `attributes._id`; the JSON:API `id`
  is the IRI).
- Attributes are spread onto the object.
- Relationships become UUID strings, or arrays of them.

Nothing downstream ever sees an IRI. Reconstructing one on write is the data layer's
job.

### Caching

Reads are `cache: 'no-store'` by default. Opt into the Next Data Cache per call:

```ts
await fetchAll<Product>('/api/products', 2000, { tags: ['ref-products'], revalidate: 300 });
```

Only do this for data every user sees identically — a cache entry may be shared
across sessions. When you cache, the matching write action must `updateTag` /
`revalidateTag` so edits show up immediately.

### Adding a new read

1. Add the DTO type to the feature: `src/features/<domain>/types.ts`.
2. Call the appropriate helper from a Server Component page or layout.
3. Pass plain data down to client components as props.

```tsx
// src/app/products/page.tsx
export default async function ProductsPage() {
  const { data } = await fetchPage<Product>('/api/products');
  return <ProductsView products={data} />;
}
```

## Writing — `src/lib/api/actions.ts` (`"use server"`)

Every action:

1. Re-validates the input with the same zod schema the client uses.
2. Calls `mutate(path, method, type, attributes, id?)`, which sends a JSON:API
   document with `Content-Type: application/vnd.api+json`.
3. On success, calls `revalidatePath` for affected routes and returns `{ data }`.
4. On 422, maps the API's `errors[]` to `fieldErrors` keyed by field name (from
   `source.pointer`).
5. On other errors, returns a top-level `error` string.

### `ActionResult<T>` envelope

```ts
interface ActionResult<T> {
  data?: T;
  error?: string;                          // form-level message
  fieldErrors?: Record<string, string>;    // per-field, name-keyed
}
```

A client form receives this envelope and:

- `fieldErrors` → `form.setError(field, { type: 'server', message })` for each key.
- `error` → top-of-form alert via `setError('root')`.
- `data` → success path (close drawer, navigate, toast).

### Adding a new write

1. Add a zod schema and a `to<Resource>Payload(values)` mapper in
   `src/features/<domain>/lib/validation.ts`.
2. Add `create<Resource>Action` / `update<Resource>Action` in `src/lib/api/actions.ts`.
3. Wire the form (below).

```ts
// src/features/product/lib/validation.ts
export const productFormSchema = z.object({ … });
export type ProductFormValues = z.infer<typeof productFormSchema>;
export function toProductPayload(values: ProductFormValues) { … }

// src/lib/api/actions.ts
export async function createProductAction(values: ProductFormValues): Promise<ActionResult<Product>> {
  const parsed = productFormSchema.safeParse(values);
  if (!parsed.success) return { fieldErrors: await zodFieldErrors(parsed.error) };
  const result = await mutate<Product>('/api/products', 'POST', 'Product', toProductPayload(parsed.data));
  if (result.data) revalidatePath('/products');
  return result;
}
```

Only export `async` functions from a `"use server"` file — Next enforces it.

## Forms — react-hook-form + zod

The pattern for every form:

1. **One schema, two consumers.** The zod schema lives in
   `src/features/<domain>/lib/validation.ts`. The client uses it via `zodResolver`;
   the Server Action re-runs `safeParse` on the same schema before hitting the API.
2. **Form values vs. API payload are separate.** `FormValues` mirrors the UI
   controls (strings, enum selects). `toPayload(values)` shapes them into the API's
   JSON (trim, `"" → null`, `parseInt`). Collapsing the two makes every input change
   an API change.
3. **Server errors map back to fields.** The action returns `fieldErrors` (from zod
   or from the API's 422); the client calls
   `form.setError(field, { type: 'server', message })` for each key.

### Skeleton

```tsx
'use client';

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { productFormSchema, type ProductFormValues } from '@/features/product/lib/validation';
import { createProductAction } from '@/lib/api/actions';

export function ProductCreateForm({ onSuccess }: { onSuccess: () => void }) {
  const form = useForm<ProductFormValues>({
    resolver: zodResolver(productFormSchema),
    defaultValues: { name: '' },
  });

  async function onSubmit(values: ProductFormValues) {
    const result = await createProductAction(values);
    if (result.fieldErrors) {
      for (const [field, message] of Object.entries(result.fieldErrors)) {
        form.setError(field as keyof ProductFormValues, { type: 'server', message });
      }
      return;
    }
    if (result.error) {
      form.setError('root', { type: 'server', message: result.error });
      return;
    }
    onSuccess();
  }

  return <form onSubmit={form.handleSubmit(onSubmit)}>{/* fields */}</form>;
}
```

### Why a schema also runs server-side

The API is the source of truth — but re-running the schema in the Server Action means:

- The client cannot bypass validation by skipping the resolver.
- The action gets a typed `parsed.data` to hand to the mapper, even though it
  receives untyped JSON over the wire.
- 422 mapping stays a fallback for genuinely server-only checks (uniqueness, FK
  existence), not for "this field is empty".

## API Platform specifics

- **POST/PUT** content type: `application/vnd.api+json` (full document).
- **PATCH** content type: `application/merge-patch+json` (only the changed fields).
- Collections carry `meta.totalItems` / `meta.itemsPerPage` and `links.next`.
- Validation errors arrive as JSON:API `errors: [{ status, detail, source: { pointer } }]`.
  The pointer looks like `/data/attributes/name` — the last segment is the form field
  name. Keep entity property names and form field names aligned so this mapping stays
  trivial.

## Authentication

The template ships with **no authentication**. When you add it, keep the seam where
it already is:

1. Store the credential in an **httpOnly** cookie set by a Server Action — never in
   `localStorage`, never in client memory.
2. Read it with `cookies()` inside `apiFetch`/`mutate` and forward it as an
   `Authorization` header. Those two functions should be the only code that touches it.
3. Handle `401` centrally in `apiFetch`: bounce through a route that **clears the
   cookie** before landing on the sign-in page. Redirecting straight to sign-in loops
   if a middleware gate judges the credential structurally valid without calling the
   API.
4. Gate routes in `src/middleware.ts`. Treat that gate as UX only — the API stays the
   authority.

## Real-time updates

There is no push transport wired. Pages re-fetch via `revalidatePath` after writes.
If a feature genuinely needs push (a live map, a shared queue), add it as a direct
subscription from the client component — do not proxy it through Next.
