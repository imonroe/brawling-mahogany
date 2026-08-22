/**
 * IA §11: a **Person**, never a User. *User* means somebody with a login, and
 * most people in this product — clients, vendors, opposing agents — have none.
 * `auth.user` is Inertia's own key for "whoever is signed in", and the person
 * behind it is a Person like everybody else.
 */
export type Person = {
    /** A ULID. Client-facing identifiers are never sequential (ADR 0001). */
    id: string;
    first_name: string;
    last_name: string | null;
    email: string;
    phone?: string | null;
    avatar?: string;
    is_super_admin?: boolean;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Impersonation = {
    /** The person being impersonated, for the banner (PRD §4.1 F1.5). */
    name: string;
    /** The team they are being impersonated inside. */
    teamName: string;
    /** The typed reason, shown so the session explains itself (S84). */
    reason: string;
    /** When the session reverts on its own. */
    endsAt: string;
    return_url?: string;
};

export type Auth = {
    user: Person;
    /**
     * Permission keys the person holds **in the resolved team**. Used to hide
     * navigation they cannot use (IA §5.1); authorisation itself is enforced
     * by policies, never by this list.
     */
    permissions: string[];
    impersonating: Impersonation | null;
    isSuperAdmin: boolean;
};

/** The resolved team (ADR 0002). Null when the person has no live membership. */
export type CurrentTeam = {
    id: string;
    name: string;
    /** PRD §9: storage is UTC, display is this zone. */
    timezone: string;
    brandAccentColor: string | null;
    logoPath: string | null;
};

/** One entry in the team switcher (S09). */
export type TeamOption = {
    id: string;
    name: string;
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
