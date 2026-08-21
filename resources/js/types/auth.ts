export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Impersonation = {
    /** The person being impersonated, for the banner (PRD §4.1 F1.5). */
    name: string;
    return_url?: string;
};

export type Auth = {
    user: User;
    /**
     * Permission keys the current person holds. Used to hide navigation the
     * person cannot use; authorisation itself is enforced by policies.
     */
    permissions: string[];
    impersonating: Impersonation | null;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
