// Monitor targets for the specs.
//
// A monitor's url + type pair is unique application-wide (a UniqueEntity constraint),
// so every monitor a spec creates must carry a unique URL — otherwise a second monitor
// in the same run, or a rerun against a not-yet-reset database, collides with itself.
//
// Both targets carry a top-level domain on purpose: the monitor URL is validated with
// #[Assert\Url], which rejects a bare host like `http://api/health`. The ticket suggested
// that in-network URL, but it does not pass the validator; the OrbStack domain that does
// (api.pulse.orb.local) resolves locally but not under plain-Docker CI. So:
//
//   - Up:   an external host the worker container can reach in BOTH environments, which
//           answers 200. A unique query string keeps the URL distinct per monitor while
//           the reachable host stays constant.
//   - Down: an unroutable *.invalid host (a reserved TLD, RFC 2606). DNS fails instantly,
//           so the check reports Down in well under a second rather than burning the full
//           8-second timeout.

let counter = 0;

function unique(): string {
  counter += 1;
  return `${Date.now().toString(36)}-${counter}-${Math.random().toString(36).slice(2, 8)}`;
}

/** A URL that resolves Up from the worker container. */
export function upTarget(): string {
  return `https://example.com/?pulse-e2e=${unique()}`;
}

/** A URL that resolves Down (fast connection failure) from the worker container. */
export function downTarget(): string {
  return `http://down-${unique()}.invalid`;
}
