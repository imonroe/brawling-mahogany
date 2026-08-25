<?php

declare(strict_types=1);

namespace App\Support\Help;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The manual, read off disk (S92 · issue #170).
 *
 * ## Markdown files rather than rows or components
 *
 * #170 asks for documentation *"which can be updated and improved as we
 * continue development"*, and that phrase decides the storage. A page written
 * as a Vue component is a page only a frontend developer can correct; a page
 * in a database needs an editor screen, a permission, an audit decision and a
 * migration before anybody can fix a typo. A Markdown file is a diff, reviewed
 * the way every other document in `docs/` is reviewed, and it keeps the manual
 * in the same pull request as the change it describes — which is the only
 * arrangement under which documentation stays true.
 *
 * `league/commonmark` is already installed: Laravel ships it for
 * `Str::markdown()`, so this adds no dependency.
 *
 * ## Raw HTML is stripped, and that is deliberate
 *
 * The content is repository-authored, so this is not an injection boundary
 * today. It is configured as though it were, for two reasons. The audience for
 * these files is explicitly *"updated and improved as we continue
 * development"* — which means people who are not thinking about CommonMark's
 * HTML passthrough — and the rendered output reaches the browser through
 * `v-html`, which is the one place in this application where a stray `<script>`
 * would run. `html_input: strip` and `allow_unsafe_links: false` cost nothing
 * and remove the question.
 */
final class HelpLibrary
{
    /**
     * Where the manual lives. Not `docs/`, which is the *engineering* record —
     * the PRD, the IA, the ADRs — and is written for a different reader.
     */
    private const PATH = 'help';

    /** @var array<string, HelpArticle>|null */
    private static ?array $articles = null;

    /**
     * The sections, in the order a person meets them.
     *
     * Ordered by when somebody needs them rather than alphabetically or by
     * how much work each took: signing in comes before running a deal, and
     * configuration comes last because it is found once.
     *
     * @var list<array{key: string, title: string, blurb: string}>
     */
    public const SECTIONS = [
        [
            'key' => 'getting-started',
            'title' => 'Getting started',
            'blurb' => 'Signing in, finding your way around, and what the app is for.',
        ],
        [
            'key' => 'deals',
            'title' => 'Running a deal',
            'blurb' => 'The transaction itself — stages, requirements, tasks and offers.',
        ],
        [
            'key' => 'people',
            'title' => 'People and properties',
            'blurb' => 'Your directory of clients and vendors, and the homes you are working on.',
        ],
        [
            'key' => 'setup',
            'title' => 'Setting your team up',
            'blurb' => 'Templates, roles, and the settings a team owner looks after.',
        ],
        [
            'key' => 'coming-later',
            'title' => 'Coming later',
            'blurb' => 'Features on the way, and what they will do when they arrive.',
        ],
    ];

    /**
     * Every article, keyed by slug.
     *
     * Read once per request and memoised. Not cached across requests on
     * purpose: the files change when the application is deployed, and a cache
     * that needs clearing to show a corrected sentence is a cache that will be
     * found stale by somebody reading the wrong instructions.
     *
     * @return array<string, HelpArticle>
     */
    public function all(): array
    {
        if (self::$articles !== null) {
            return self::$articles;
        }

        $directory = resource_path(self::PATH);

        if (! File::isDirectory($directory)) {
            return self::$articles = [];
        }

        $articles = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $article = $this->parse(
                Str::before($file->getFilename(), '.md'),
                (string) File::get($file->getPathname()),
            );

            $articles[$article->slug] = $article;
        }

        uasort(
            $articles,
            fn (HelpArticle $a, HelpArticle $b): int => [$this->sectionRank($a->section), $a->order]
                <=> [$this->sectionRank($b->section), $b->order],
        );

        return self::$articles = $articles;
    }

    public function find(string $slug): ?HelpArticle
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * The index: every section with the articles under it.
     *
     * A section with nothing in it is not rendered — the same rule the global
     * search overlay follows, and for the same reason: an empty heading buries
     * what is beneath it.
     *
     * @return list<array<string, mixed>>
     */
    public function sections(): array
    {
        $articles = $this->all();

        $sections = [];

        foreach (self::SECTIONS as $section) {
            $cards = array_values(array_map(
                fn (HelpArticle $article): array => $article->toCard(),
                array_filter(
                    $articles,
                    fn (HelpArticle $article): bool => $article->section === $section['key'],
                ),
            ));

            if ($cards === []) {
                continue;
            }

            $sections[] = [...$section, 'articles' => $cards];
        }

        return $sections;
    }

    /**
     * The article before and after this one, so a reader can walk the manual.
     *
     * In the same order the index shows, which is the order somebody meets the
     * product — so *Next* is genuinely the next thing to learn rather than the
     * next thing alphabetically.
     *
     * @return array{previous: array<string, mixed>|null, next: array<string, mixed>|null}
     */
    public function neighbours(HelpArticle $article): array
    {
        $slugs = array_keys($this->all());
        $position = array_search($article->slug, $slugs, true);

        if ($position === false) {
            return ['previous' => null, 'next' => null];
        }

        $at = function (int $index) use ($slugs): ?array {
            $slug = $slugs[$index] ?? null;

            return $slug === null ? null : $this->all()[$slug]->toCard();
        };

        return [
            'previous' => $position > 0 ? $at($position - 1) : null,
            'next' => $at($position + 1),
        ];
    }

    private function sectionRank(string $key): int
    {
        foreach (self::SECTIONS as $index => $section) {
            if ($section['key'] === $key) {
                return $index;
            }
        }

        // A file naming a section this class does not know sorts last rather
        // than throwing: a typo in frontmatter should not take the manual down.
        return count(self::SECTIONS);
    }

    private function parse(string $slug, string $contents): HelpArticle
    {
        [$frontmatter, $body] = $this->split($contents);

        [$html, $headings] = $this->anchor(
            $this->converter()->convert($body)->getContent(),
        );

        return new HelpArticle(
            slug: $slug,
            title: $frontmatter['title'] ?? Str::headline($slug),
            summary: $frontmatter['summary'] ?? '',
            section: $frontmatter['section'] ?? 'getting-started',
            order: (int) ($frontmatter['order'] ?? 99),
            html: $html,
            headings: $headings,
            arrivesWith: $frontmatter['arrives_with'] ?? null,
        );
    }

    /**
     * Frontmatter and body.
     *
     * A deliberately small parser rather than a YAML dependency: the manual's
     * frontmatter is five flat string keys, and every one of them is listed in
     * `HelpArticle`'s constructor. A file that needs more structure than this
     * is a file that has stopped being documentation.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function split(string $contents): array
    {
        $contents = str_replace("\r\n", "\n", $contents);

        if (! str_starts_with($contents, "---\n")) {
            return [[], $contents];
        }

        $end = strpos($contents, "\n---\n", 4);

        if ($end === false) {
            return [[], $contents];
        }

        $frontmatter = [];

        foreach (explode("\n", substr($contents, 4, $end - 4)) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            $frontmatter[trim(Str::before($line, ':'))] = trim(
                trim(trim(Str::after($line, ':')), '"\''),
            );
        }

        return [$frontmatter, substr($contents, $end + 5)];
    }

    /**
     * Give every heading an id, and return the contents list built from the
     * **same** pass.
     *
     * One pass rather than two, and that is the whole point. This was two
     * functions — one reading the Markdown for the contents list, one
     * rewriting the HTML — each slugging its own copy of the text, and they
     * disagreed the moment a heading contained a character CommonMark
     * escapes: `## Dates & Deadlines` gave the contents list
     * `dates-deadlines` and the heading `dates-amp-deadlines`, because
     * `strip_tags` leaves `&amp;` behind for `Str::slug` to read as a word.
     * A contents link that scrolls nowhere is the most obvious possible
     * defect in a manual.
     *
     * Deriving both from one traversal makes them agree by construction,
     * which is the same rule this codebase applies to every other pair of
     * things that must not drift.
     *
     * **Duplicates are numbered.** Two `## What it will do` headings in one
     * article — which the *Coming later* pages plausibly grow — would
     * otherwise produce two identical ids, and every link to the second one
     * would land on the first.
     *
     * @return array{0: string, 1: list<array{level: int, text: string, id: string}>}
     */
    private function anchor(string $html): array
    {
        $headings = [];
        $seen = [];

        $html = (string) preg_replace_callback(
            '/<h([23])>(.*?)<\/h\1>/s',
            function (array $match) use (&$headings, &$seen): string {
                $text = $this->plain($match[2]);
                $id = Str::slug($text);

                // A heading of only punctuation slugs to nothing; number it
                // rather than emitting `id=""`, which no link can reach.
                if ($id === '') {
                    $id = 'section';
                }

                $seen[$id] = ($seen[$id] ?? 0) + 1;

                if ($seen[$id] > 1) {
                    $id .= '-'.$seen[$id];
                }

                $headings[] = ['level' => (int) $match[1], 'text' => $text, 'id' => $id];

                return "<h{$match[1]} id=\"{$id}\">{$match[2]}</h{$match[1]}>";
            },
            $html,
        );

        return [$html, $headings];
    }

    /**
     * A heading's words, with its markup and its entities resolved.
     *
     * `html_entity_decode` is the half that was missing: the text arrives from
     * rendered HTML, where CommonMark has already escaped `&`, `<` and `"`,
     * and `Str::slug` reads `&amp;` as the word *amp*.
     */
    private function plain(string $text): string
    {
        return trim(html_entity_decode(
            strip_tags($text),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
    }

    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            // Repository content today; configured as an injection boundary
            // anyway, because the audience for these files is people editing
            // prose and the output reaches the browser through `v-html`.
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        return new MarkdownConverter($environment);
    }
}
