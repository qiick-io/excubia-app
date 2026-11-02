/** One main-sidebar module: visibility and whether it can be hidden. */
export type SidebarModuleSetting = {
    id: string;
    enabled: boolean;
    locked: boolean;
};

/** Project settings shared with every page (HandleInertiaRequests). */
export type SharedProjectSettings = {
    sidebarModules: SidebarModuleSetting[];
    reportBugUrl: string | null;
};
