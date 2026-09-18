# Frontend architecture

## Layout

Everything ships under `app/src/`. The `@/*` TypeScript path alias maps to
`./src/*`, so `@/features/product/types` resolves to `src/features/product/types.ts`.

```
app/
├── src/
│   ├── app/                  # Routes — App Router pages, layouts, error/not-found
│   │   ├── layout.tsx
│   │   ├── globals.css       # Tailwind + design tokens
│   │   ├── page.tsx
│   │   ├── error.tsx  global-error.tsx  not-found.tsx
│   ├── features/             # Per-domain folders (see below)
│   │   └── example/          # EXAMPLE — the shipped model of the convention
│   │       └── types.ts
│   ├── components/
│   │   ├── ui/               # shadcn primitives
│   │   └── providers.tsx     # global client providers (TooltipProvider, …)
│   ├── lib/
│   │   ├── api/
│   │   │   ├── client.ts     # Server Component reads
│   │   │   └── actions.ts    # "use server" writes
│   │   └── utils.ts          # cn() — clsx + tailwind-merge
│   ├── types/                # cross-cutting types
│   │   └── api.ts            # ApiResource, JsonApiCollection, JsonApiError
│   └── hooks/                # cross-cutting client hooks
└── public/                   # Static assets
```

## The three buckets

Every file you add belongs to exactly one bucket. The bucket determines what it may
import.

### 1. Routes — `src/app/`

App Router pages, layouts, error and not-found boundaries. Routes are thin: they
`await` `params`/`searchParams`, fetch via `lib/api/client.ts`, and compose
components from features + UI primitives. They own no business logic.

Routes may import from any other bucket.

### 2. Features — `src/features/<domain>/`

One folder per business domain. Conventional shape:

```
features/<domain>/
├── components/           # domain components (one per file)
├── lib/
│   └── validation.ts     # zod schema + form↔payload mappers
└── types.ts              # DTO types (the shape returned by the API)
```

Rules:

- A feature **may** import from `@/components/ui`, `@/lib`, `@/types`, and other
  features (cross-feature imports are fine — orders reads customer types, etc.).
- A feature **must not** import from `@/app/...` — routes compose features, not the
  other way around.
- DTO types live in the feature even though `lib/api/*` imports them. This is a
  deliberate `lib → feature` dependency: types stay with the domain that owns them.

### 3. Global UI — `src/components/`

Domain-agnostic, prop-driven presentational pieces.

- **`ui/`** — shadcn/ui components. Install more with `npx shadcn@latest add <name>`
  from inside `app/` (locally, not through Docker).

Global UI **must not** import from `features/` or `app/`. It may import from
`@/lib/utils` and sibling UI files.

## Cross-cutting plumbing

| Path | Purpose |
| --- | --- |
| `src/lib/api/client.ts` | Reads for Server Components. `apiFetch` + `fetchResource` / `fetchPage` / `fetchAll`, and the JSON:API → flat-object flattening. |
| `src/lib/api/actions.ts` | `"use server"` writes. Zod re-validation → POST/PATCH → `revalidatePath`. Returns `ActionResult<T>` with `data`/`error`/`fieldErrors`. |
| `src/types/api.ts` | `ApiResource`, `JsonApiCollection<T>`, `JsonApiError` — shared by every domain. |
| `src/lib/utils.ts` | `cn()` — the only utility to reach for when composing conditional Tailwind classes. |

## Why this shape

- **What changes together stays together.** A domain's view + its types + its zod
  schema + its payload mapper all live under `features/<domain>/`. You don't grep the
  codebase to find them.
- **Global UI has no business knowledge.** Anything in `components/ui/` should be
  reusable in a different product. If it knows about your domain, it belongs in a
  feature.
- **Routes are composition only.** Adding a page should mean reaching into one or two
  features, not duplicating logic.

## Adding code — where does it go?

- **New page** → `src/app/<route>/page.tsx`. Server component unless it truly needs `useState`/`useRouter`.
- **New domain component** → `src/features/<domain>/components/`.
- **New API read** → add a call in the page, or a small wrapper in the feature; the generic helpers live in `src/lib/api/client.ts`.
- **New API write** → add an action in `src/lib/api/actions.ts`. See [data-and-forms.md](./data-and-forms.md).
- **New shadcn primitive** → `cd app && npx shadcn@latest add <name>`. Lands in `src/components/ui/`.
