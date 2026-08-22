/**
 * IA §11: a **Person**, never a User. *User* means somebody with a login, and
 * most people in this product — clients, vendors, opposing agents — have none.
 * `auth.user` is Inertia's own key for "whoever is signed in", and the person
 * behind it is a Person like everybody else.
 *
 * **These are the five keys `HandleInertiaRequests::personProps()` sends, and
 * no others.** It carried `phone`, `avatar`, `is_super_admin`,
 * `two_factor_enabled`, `created_at` and `updated_at` for a while after the
 * server stopped sending any of them — two of those declared *required*, so
 * the type was not merely incomplete, it asserted the presence of fields that
 * are never there. Nothing read them, which is why it went unnoticed and is
 * also why it was worth fixing before something did. `isSuperAdmin` on `Auth`
 * below is the one that is really sent.
 */
export type Person = {
    /** A ULID. Client-facing identifiers are never sequential (ADR 0001). */
    id: string;
    /**
     * From the **membership**, not the person (#140): a name is something a
     * team recorded, and two teams may have written it differently. With no
     * resolved team the server falls back to the part of the address before
     * the @, which is the only name anybody knows at that point.
     */
    first_name: string;
    last_name: string | null;
    /**
     * The **sign-in** address, and null for somebody who cannot sign in.
     * The address a team holds for a person is on their membership and is
     * allowed to differ.
     */
    email: string | null;
    email_verified_at: string | null;
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

/**
 * An invitation waiting for whoever is signed in (ADR 0003 · S09).
 *
 * Shared on every response rather than fetched per screen: the shell renders
 * a banner from it, and somebody who has just been invited is by definition
 * somebody who does not know where to go looking.
 */
export type PendingInvitation = {
    id: string;
    teamName: string;
    /** The role the invitation carries, by its display name. */
    role: string;
    expiresAt: string;
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
