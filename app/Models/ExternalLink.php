<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Links\SafeUrl;
use App\Support\Tenancy\ForeignReferenceException;
use Database\Factories\ExternalLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A link out to somewhere else (PRD §4.3 F3.4, §7.13, §10 · issue #61).
 *
 * PRD §7.13 replaced `zillow_url` and its siblings with this: *"Per-site link
 * columns will not scale."* A label and a URL is the whole shape, and the
 * twelfth site an agent cares about is a row rather than a migration.
 *
 * **Only ever a link.** PRD §10 permits storing the URL and nothing from the
 * other end of it — no title, no price, no photo, no description. There is no
 * column here for any of those, and there must not be one: MLS content is
 * licensed, and a `fetched_title` added for convenience is the first step
 * across that line.
 *
 * ## Two guards this model carries itself
 *
 * The polymorphic pointer is outside ADR 0002's second layer — Postgres has no
 * single table to reference `(team_id, linkable_id)` against — so the tenancy
 * check that a composite key would have made unrepresentable is written here.
 * And the URL is rendered as an `href`, so the scheme allowlist is here too
 * rather than only in the form request that happens to exist today.
 *
 * Both run on `creating` **and** `updating`, and deliberately not on
 * `saving`: `saving` fires first, and `BelongsToTeam` fills `team_id` on
 * `creating` — so a tenancy guard on `saving` compares a real property against
 * a `team_id` that is still null and refuses every insert. `Deal::booted()`
 * carries the same note, having shipped that bug first.
 *
 * An update is guarded as well as an insert: repointing a link at another
 * team's property, or rewriting a stored URL to `javascript:`, has to meet the
 * same refusal.
 *
 * @property string $id
 * @property string $team_id
 * @property string $linkable_type
 * @property string $linkable_id
 * @property string $label
 * @property string $url
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['label', 'url', 'sort_order'])]
class ExternalLink extends Model
{
    /** @use HasFactory<ExternalLinkFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * What may be linked from.
     *
     * An allowlist rather than "any model", because `linkable_type` is a class
     * name written into a database column and the guard below has to be able
     * to load it. Every entry must be team-scoped: the tenancy check reads the
     * target's `team_id`, and a target without one has no answer to give.
     *
     * Deals join this list in #62; nothing else is planned.
     *
     * @var list<class-string<Model>>
     */
    public const LINKABLE = [
        Property::class,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $link): void {
            $link->guardUrl();
            $link->guardLinkable();
        };

        static::creating($guard);
        static::updating($guard);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo(type: 'linkable_type', id: 'linkable_id');
    }

    /**
     * An `href` is not inert, and a stored `javascript:` URL is stored XSS.
     *
     * `SafeUrl` carries the reasoning and the allowlist. Refused here as well
     * as in the form request because the next writer is a seeder or #62's
     * screen, and neither goes through a request this one validated.
     */
    private function guardUrl(): void
    {
        if (! $this->isDirty('url')) {
            return;
        }

        if (! SafeUrl::permits($this->url)) {
            throw new InvalidArgumentException(SafeUrl::message());
        }
    }

    /**
     * The check a composite foreign key would have made unnecessary.
     *
     * ADR 0002 layer 2 carries `team_id` into every foreign key so a
     * cross-tenant pointer is unrepresentable. A polymorphic pointer has no
     * one table to reference, so this stands in — and it is written on the
     * model rather than in a controller for the same reason Slice 1's identity
     * rule ended up on `Person`: a rule that lives in one caller is a rule the
     * second caller is written without.
     *
     * Note what it does **not** cover, because the honest sentence is worth
     * more than the reassuring one: model events, so `saveQuietly()`, a
     * query-builder write, and `ExternalLink::query()->update(…)` all skip it.
     * `tests/Isolation/ExternalLinkIsolationTest.php` is what pins the
     * behaviour; this is what makes the ordinary path safe.
     */
    private function guardLinkable(): void
    {
        if (! $this->isDirty(['linkable_type', 'linkable_id', 'team_id'])) {
            return;
        }

        $type = (string) $this->linkable_type;

        if (! in_array($type, self::LINKABLE, true)) {
            throw new InvalidArgumentException("[{$type}] cannot carry external links.");
        }

        /*
         * Scoped, and that is the point rather than an oversight.
         *
         * The tempting version of this lifts the global scope so the guard can
         * say *"that row is another team's"* precisely. It would also be the
         * one place in `app/` reading tenant data unscoped, which ADR 0002
         * does not sanction and `UnscopedQueryConventionTest` refuses — and it
         * buys nothing, because the scoped query already gives the right
         * answer: another team's property is invisible here, so it comes back
         * null and the refusal below fires.
         *
         * The second condition is not redundant with the first. It catches the
         * case the scope cannot see: a link whose own `team_id` was set to
         * something other than the resolved team, which `BelongsToTeam` allows
         * during a super-admin bypass.
         *
         * `withTrashed()` because this guard is about tenancy and not about
         * lifecycle. A soft-deleted property still belongs to its team, and
         * treating it as absent would turn a question about ownership into a
         * question about deletion.
         */
        /** @var Model|null $target */
        $target = $type::query()->withTrashed()->whereKey($this->linkable_id)->first();

        if ($target === null || $target->getAttribute('team_id') !== $this->team_id) {
            throw ForeignReferenceException::for(
                (new $type)->getTable(),
                (string) $this->linkable_id,
                $this->team_id,
            );
        }
    }
}
