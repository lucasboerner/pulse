import { execFileSync } from "node:child_process";
import { resolve } from "node:path";

// Everything that reaches into the compose stack from the test process. Per the repo
// convention, database resets and console commands run through `docker compose`, not
// against the host. The Playwright process itself runs on the host.

// Playwright runs from app/, so the compose project lives one directory up. Overridable
// for an unusual checkout layout.
const COMPOSE_DIR = process.env.PULSE_COMPOSE_DIR ?? resolve(process.cwd(), "..");

function compose(args: string[], input?: string): string {
  return execFileSync("docker", ["compose", ...args], {
    cwd: COMPOSE_DIR,
    encoding: "utf8",
    input,
    stdio: input ? ["pipe", "pipe", "pipe"] : ["ignore", "pipe", "pipe"],
  });
}

// Truncate every table except Doctrine's migration bookkeeping, which must survive so the
// api container does not try to re-run every migration on its next boot. CASCADE clears
// the foreign-key web in one statement; the schema itself is left in place.
const RESET_SQL =
  "DO $$ DECLARE r RECORD; BEGIN " +
  "FOR r IN (SELECT tablename FROM pg_tables WHERE schemaname = 'public' " +
  "AND tablename <> 'doctrine_migration_versions') LOOP " +
  "EXECUTE 'TRUNCATE TABLE ' || quote_ident(r.tablename) || ' RESTART IDENTITY CASCADE'; " +
  "END LOOP; END $$;";

/** Empty the database (schema kept), so the run starts from a known-empty instance. */
export function resetDatabase(): void {
  const user = process.env.POSTGRES_USER ?? "app";
  const db = process.env.POSTGRES_DB ?? "app";
  compose(["exec", "-T", "database", "psql", "-v", "ON_ERROR_STOP=1", "-U", user, "-d", db, "-c", RESET_SQL]);
}

/** Create the test operator, tolerating the case where it already exists. */
export function createOperator(username: string, email: string, password: string): void {
  // app:user:create is interactive: username, e-mail, password, repeat. Feeding stdin to
  // a non-TTY exec answers each prompt. A duplicate exits non-zero, which we ignore.
  const answers = `${username}\n${email}\n${password}\n${password}\n`;
  try {
    compose(["exec", "-T", "api", "bin/console", "app:user:create"], answers);
  } catch {
    // Already exists (or another benign non-zero) — the reset means this is rare, but a
    // second run against a not-yet-reset database must not fail here.
  }
}

/** Stop a single service (used by the dead-hub spec to take Mercure down). */
export function stopService(name: string): void {
  compose(["stop", name]);
}

/** Start a service and wait until it reports healthy (or running, if it has no check). */
export function startService(name: string, timeoutMs = 60_000): void {
  compose(["start", name]);
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const id = compose(["ps", "-q", name]).trim();
    if (id) {
      const status = execFileSync(
        "docker",
        ["inspect", "-f", "{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}", id],
        { cwd: COMPOSE_DIR, encoding: "utf8" },
      ).trim();
      if (status === "healthy" || status === "running") return;
    }
    execFileSync("sleep", ["1"]);
  }
  throw new Error(`service ${name} did not become healthy within ${timeoutMs}ms`);
}
