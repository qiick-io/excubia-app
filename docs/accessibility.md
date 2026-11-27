# WCAG 2.2 AA verification

Excubia App targets [WCAG 2.2 AA](https://www.w3.org/TR/WCAG22/). The first review ran against `develop` at `80fb509c` in Chromium on 2026-11-27. It covered the sign-in, setup and error pages, dashboard and main project, fleet and administration pages in the browser smoke suite. Light, dark and high-contrast states were sampled at desktop and mobile widths.

| Criterion | Evidence and follow-up |
| --- | --- |
| 2.5.8 Target Size (Minimum) | The shared `target-size` axe rule reported no violations in 67 desktop and 67 mobile page and theme states. The sidebar order page also passed the rule at both widths. The rule covers target size and the spacing exception. Review other WCAG exceptions and untested overlays in context. |
| 2.4.11 Focus Not Obscured (Minimum) | `Wcag22BrowserTest.php` checks focus visibility on Appearance → Sidebar at desktop width in light and dark. On mobile, the sticky Save bar overlapped two focused Groups controls. The viewport hit-test reached the footer instead. Review visibility and focus contrast under the translucent footer in [#538](https://github.com/qiick-io/excubia-app/issues/538). |
| 2.5.7 Dragging Movements | `TimeRangeBrowserTest.php` exercises custom date selection as an alternative to dragging a chart range. Sidebar reordering has no click or tap alternative. [#537](https://github.com/qiick-io/excubia-app/issues/537) |
| 3.3.8 Accessible Authentication (Minimum) | `Wcag22BrowserTest.php` exercises clipboard paste on sign-in and at the two-factor challenge, and checks the sign-in autocomplete hint. Review password managers, alternate challenges and hosted identity providers in their environment. |
| 3.3.7 Redundant Entry | `Wcag22BrowserTest.php` verifies that the password-reset form reuses the email in the reset link. Review other multi-step flows when their inputs change. |
| 3.2.6 Consistent Help | `Wcag22BrowserTest.php` opens keyboard help with the same command on the dashboard and profile settings. Review other repeated help mechanisms in their layout context. |

Run the existing smoke with the shared target-size script and the product flow tests from an app checkout with its test dependencies installed and assets built:

```sh
QIICK_WCAG22_AXE_SCRIPT="$APP_UI_WORKTREE/scripts/wcag22-axe.js" \
  vendor/bin/pest tests/Browser/A11ySmokeBrowserTest.php tests/Browser/Wcag22BrowserTest.php tests/Browser/TimeRangeBrowserTest.php
```

`APP_UI_WORKTREE` is the path to a checkout with the [shared WCAG 2.2 assertion](https://github.com/qiick-io/app-ui/pull/295). Adopt the workflow input after the shared change is reachable from app-ui `develop` or a release tag. This audit covers the sampled routes and interactions. A full WCAG 2.2 AA review also needs the remaining states, browser and assistive technology combinations, and manual checks.
