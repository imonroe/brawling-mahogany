<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\SaveAutomationRequest;
use App\Models\ActionDefinition;
use App\Models\StageTemplate;
use App\Models\WorkflowTemplate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * S44 — the automation editor (PRD §4.5 F5.1–F5.4, F5.10 · issue #91).
 *
 * A modal on the template editor rather than a screen of its own, because an
 * automation only exists on a stage and the Screen Inventory says so.
 *
 * Every action authorizes against the **workflow template**, which is the rule
 * `routes/web.php` states for every nested template route: one pack is shared
 * by every team, `WorkflowTemplatePolicy::update()` refuses a system row, and
 * a policy guarding the parent while a child route lets somebody attach an
 * automation is a guard with a door beside it.
 */
class AutomationController extends Controller
{
    public function store(SaveAutomationRequest $request, WorkflowTemplate $template, StageTemplate $stageTemplate): RedirectResponse
    {
        $automation = new ActionDefinition;

        $automation->fill($this->attributes($request, activeByDefault: true));

        $automation->forceFill([
            /*
             * Mirrors the parent's, always — the migration argues why at
             * length. A system stage template's automation is shared by every
             * team, and the CHECK constraint then refuses to let it name a
             * message template at all.
             */
            'team_id' => $template->team_id,
            'stage_template_id' => $stageTemplate->getKey(),
            'sort_order' => (int) $stageTemplate->actionDefinitions()->max('sort_order') + 1,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation added.')]);

        return back(fallback: route('templates.show', $template));
    }

    public function update(SaveAutomationRequest $request, WorkflowTemplate $template, StageTemplate $stageTemplate, ActionDefinition $actionDefinition): RedirectResponse
    {
        // Keeping, not activating. See `attributes()`.
        $actionDefinition->fill($this->attributes($request, activeByDefault: $actionDefinition->is_active))->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation saved.')]);

        return back(fallback: route('templates.show', $template));
    }

    public function destroy(WorkflowTemplate $template, StageTemplate $stageTemplate, ActionDefinition $actionDefinition): RedirectResponse
    {
        $this->authorize('update', $template);

        /*
         * A soft delete, and not the archive-never-delete rule that governs
         * message templates — the difference is what points at it. A message
         * template is a value other rows name; an automation names things and
         * is named by nothing. Instances snapshot it (#92), so removing one
         * stops it firing again and changes no deal already running.
         */
        $actionDefinition->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation removed.')]);

        return back(fallback: route('templates.show', $template));
    }

    /**
     * The form's three-way execution choice, back into the two columns PRD
     * §6.2 names.
     *
     * One place, so `store` and `update` cannot disagree — and so the pair is
     * always written together. Writing one without the other is how a row
     * reaches the state the CHECK constraint refuses.
     *
     * `$activeByDefault` is what the flag falls back to when the request does
     * not carry it, and the two callers want different answers: a new
     * automation is on, and an edit **keeps what it had**. Defaulting an
     * update to `true` silently turned a switched-off automation back on — the
     * dialog always sends the key, so only a hand-written or later caller hit
     * it, and this is the flag that decides whether something fires.
     *
     * @return array<string, mixed>
     */
    private function attributes(SaveAutomationRequest $request, bool $activeByDefault): array
    {
        $validated = $request->validated();
        $mode = $validated['executionMode'];

        return [
            'trigger' => $validated['trigger'],
            'action_type' => $validated['action_type'],
            'message_template_id' => $validated['message_template_id'] ?? null,
            'config' => $validated['config'] ?? [],
            'is_manual' => $mode === 'manual',
            'requires_approval' => $mode === 'approval',
            'is_active' => $validated['is_active'] ?? $activeByDefault,
        ];
    }
}
