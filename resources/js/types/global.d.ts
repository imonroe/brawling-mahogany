import type { Auth, PendingInvitation } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            /** ADR 0003 — see `PendingInvitation`. */
            invitations: PendingInvitation[];
            /**
             * PRD §6.3 lookups the **shell** itself renders, and only those.
             *
             * Contact types are here because S26's Log contact button lives in
             * the top bar, which no page controller supplies props to. Null
             * with no resolved team, which is every auth screen.
             */
            lookups: { contactTypes: Record<string, string> } | null;
            /**
             * The n8n bug-report form (issue #176), or null when it is not
             * configured, switched off, or nobody is signed in. All three are
             * one absence to the shell: there is no button.
             */
            bugReport: { url: string } | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
