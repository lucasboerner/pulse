import { Wordmark } from "@/features/shell/components/wordmark";
import { SidebarNav } from "@/features/shell/components/sidebar-nav";
import { ThemeSwitch } from "@/features/shell/components/theme-switch";
import { LogoutButton } from "@/features/shell/components/logout-button";

// The mobile top bar, shown only below md where the sidebar rail is hidden: the
// compact wordmark with the theme switch and sign-out opposite it, and beneath them
// the two pages side by side. No drawer — with two entries the navigation is
// cheaper to show than to hide.
export function MobileNav() {
  return (
    <div className="flex shrink-0 flex-col gap-2.5 px-4 pt-3 pb-2 md:hidden">
      <div className="flex items-center justify-between gap-3">
        <Wordmark compact />
        <div className="flex shrink-0 items-center gap-3">
          <ThemeSwitch />
          <LogoutButton className="w-auto px-2" />
        </div>
      </div>

      <SidebarNav />
    </div>
  );
}
