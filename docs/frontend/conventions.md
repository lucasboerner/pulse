# Frontend conventions & tooling

## Imports

**Always absolute, via the `@/` alias.** It maps to `./src/*` (see `tsconfig.json`).
No relative imports — not for parents, not for siblings.

```ts
// ✅
import { Button } from '@/components/ui/button';
import { fetchPage } from '@/lib/api/client';
import type { Product } from '@/features/product/types';

// ❌
import { Button } from '../../../components/ui/button';
import { ProductRow } from './product-row';
```

This is enforced by convention, not lint — code review pushes back.

## Files & components

- **One component per file.** Each file exports exactly one component. Helper
  components get their own file even if they're three lines.
- **Named exports** for components and utilities. Default exports are only for
  Next.js `page`/`layout`/`error`/`loading` files (the framework requires it).
- **Named `interface` for props**, declared directly above the component. Never
  inline type literals.

```tsx
// ✅
interface CardProps {
  title: string;
  children: React.ReactNode;
}
export function Card({ title, children }: CardProps) { … }

// ❌ inline type
export function Card({ title, children }: { title: string; children: React.ReactNode }) { … }
```

- **Component files are kebab-case** (`product-summary-card.tsx`). Component names
  are PascalCase (`ProductSummaryCard`).
- Prefer `const Foo = (…) => …` arrow components. Use `function Foo(…)` when JSDoc
  above the declaration is helpful.

## TypeScript

- Strict mode is on. Avoid `any`; use `unknown` + narrowing or proper types.
- API DTO types live in the feature folder that owns the domain
  (`src/features/<domain>/types.ts`).
- Shared envelope types live in `src/types/api.ts`.
- Use `satisfies` for config objects to keep types narrow without losing the literal type.

### Empty-interface lint trap

`interface Foo extends SomeHTMLProps {}` trips `@typescript-eslint/no-empty-object-type`.
Use a `type` alias:

```ts
type Foo = SomeHTMLProps;
```

## Tailwind CSS 4

- Tokens are declared in `src/app/globals.css` (`:root` / `.dark` custom properties)
  and exposed as Tailwind utilities via `@theme inline`. There is **no
  `tailwind.config.js`** — Tailwind 4 is CSS-first.
- Never put `var(--token)` inside a `className` string. Add the property to
  `@theme inline` and use the generated utility.
- Always compose conditional classes with `cn()` from `@/lib/utils` — never string
  concatenation, never template literals.
- Adding a token means declaring it in **both** `:root` and `.dark`, then mapping it
  in `@theme inline`. Skipping the dark value produces a token that silently
  inherits the light one.

### Spacing — decimal scale, never `[Npx]`

Tailwind 4 uses `--spacing: 0.25rem`, so any decimal multiple works for spacing
utilities. Convert `Npx → N/4`.

```tsx
// ✅
py-2.25  gap-1.75  px-4.5  w-5.5  mt-1.75  h-6.5

// ❌
py-[9px]  gap-[7px]  px-[18px]  w-[22px]
```

Quick reference (1 unit = 4px):

| Value | px |
| --- | --- |
| `1` | 4 |
| `1.5` | 6 |
| `1.75` | 7 |
| `2` | 8 |
| `2.25` | 9 |
| `2.5` | 10 |
| `3` | 12 |
| `3.5` | 14 |
| `4` | 16 |
| `4.5` | 18 |
| `5` | 20 |
| `6` | 24 |
| `8` | 32 |

### Font sizes — always `text-[Npx]` for off-scale values

The decimal scale **does not** generate font-size CSS. `text-3.25` produces nothing.
Use explicit pixel syntax:

```tsx
// ✅
text-[11px]  text-[12px]  text-[13px]  text-[15px]  text-[26px]

// ❌ no font-size CSS is generated
text-2.75  text-3.25
```

Named utilities (`text-sm`, `text-2xl`) work but bundle a line-height — avoid them in
dense layouts unless you also set `leading-none`.

### Inset borders

Prefer `ring-1 ring-inset ring-<color>` over a custom `box-shadow`:

```tsx
ring-1 ring-inset ring-border      // default border
ring-1 ring-inset ring-black/8     // avatar inner shadow
```

### Animations

Keyframes live in `globals.css`. CSS custom properties don't survive Tailwind's
arbitrary `animation-[…]` value, so reference them via inline style:

```tsx
style={{ animation: 'my-pulse 2s ease-in-out infinite' }}
```

## Server vs. client components

Default to server components. Only add `'use client'` when you need:

- `useState`, `useEffect`, `useRef`, `useMemo`, `useCallback` — any hook with state or effects.
- `useRouter`, `usePathname`, `useSearchParams` — client navigation hooks.
- Browser event handlers (`onClick`, `onChange`, …) inline on the element.
- Browser-only APIs (`window`, `document`, `localStorage`).

Keep the boundary tight: a server shell can render a small client island. Push
`'use client'` down to the leaf that actually needs it, not up to the page.

### Hooks you can't use inside Dialog/Sheet portals

`useRouter` and `useSearchParams` may suspend the component inside a Radix portal
and silently break navigation. Use local `useState` for tab/section state inside
dialogs. Bookmarkable URL state belongs on the page, not inside a modal.

## URL state vs. context

- **URL params** for bookmarkable/shareable state (filters, current tab on a page).
  Read server-side from `searchParams`, push client-side with `router.push`.
- **React context** for global UI state that shouldn't pollute the URL (a settings
  dialog being open). Provider at layout level, hook at the consumer.

## Commands

```bash
# Docker (preferred — matches CI)
docker compose up -d app
docker compose exec -T app yarn build
docker compose exec -T app yarn lint
docker compose exec -T app yarn typecheck

# Local
cd app && yarn dev          # http://localhost:3000
cd app && yarn build

# shadcn — local only, never via Docker
cd app && npx shadcn@latest add <component>
cd app && npx shadcn@latest add <component> --overwrite
```

## Common pitfalls

- **Promise `searchParams`/`params`.** Awaiting them is mandatory in Next 16;
  forgetting throws at runtime.
- **`next/image` sizing.** Provide explicit `width`/`height`, or `fill` with a sized
  parent.
- **Empty-interface lint error.** See above — use `type` aliases.
- **`useSearchParams`/`useRouter` inside a Dialog or Sheet.** Suspends inside the
  Radix portal and silently breaks navigation. Use local state.
- **The dev server runs webpack on purpose** (`next dev --webpack` in `package.json`)
  because of a Turbopack stale-cache bug: after a save it may 500 with
  `Unterminated string constant` / `Expected '</', got '<eof>'` on a file that is
  actually valid (`tsc` passes — Turbopack read it mid-write), and one bad module
  500s *all* routes in dev. Don't drop the `--webpack` flag without retesting; if you
  do hit it, `touch` the reported file to force a recompile.
- **Reinstalling a shadcn component you customized.** `npx shadcn@latest add <name>
  --overwrite` wipes local edits. Diff before committing.
