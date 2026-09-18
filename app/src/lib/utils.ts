import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Detects WebKit/Safari "phantom" clicks. When a click handler unmounts the
 * element that was clicked (e.g. selecting a combobox option that then renders a
 * chip, or removing one chip from a row of chips), Safari re-dispatches a second
 * click — `detail: 0`, carrying the *original* pointer coordinates — to whichever
 * element now occupies that position. That stray click silently fires the wrong
 * handler (un-selecting the option just picked, or cascading a single remove into
 * "remove all"). A genuine pointer click always lands inside the target's box; a
 * keyboard activation reports coordinates (0,0). So a click whose non-zero
 * coordinates fall outside the target's rect is the phantom — callers ignore it.
 */
export function isGhostClick(e: { clientX: number; clientY: number; currentTarget: Element }): boolean {
  if (e.clientX === 0 && e.clientY === 0) return false; // keyboard (Enter/Space) → genuine
  const r = e.currentTarget.getBoundingClientRect();
  return e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
}
