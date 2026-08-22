<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * System health (Screen Inventory S85).
 *
 * *"The operator's one screen for 'is anything on fire'."*
 *
 * Several of these panels have no data until a later slice, and the important
 * decision is what they show meanwhile: issue #54 asks for panels that *"state
 * plainly that their slice has not shipped rather than showing zeros that read
 * as healthy"*. A zero next to "Bounce rate" looks like perfect deliverability
 * and is actually no SES integration.
 */
class HealthController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Admin/Health', [
            'queue' => $this->queue(),
            'panels' => [
                [
                    'key' => 'automation_failures',
                    'label' => 'Automation failure rate',
                    'target' => 'Under 1% of queued actions',
                    'slice' => 3,
                ],
                [
                    'key' => 'ses_reputation',
                    'label' => 'SES reputation',
                    'target' => 'Bounce under 2%, complaint under 0.1%',
                    'slice' => 3,
                ],
                [
                    'key' => 'ai_spend',
                    'label' => 'Extraction spend against the cap',
                    // PRD §14.3: extraction cost grows with deal volume rather
                    // than team count, so it is tracked and capped from day one
                    // of Slice 5. The panel exists now so the cap has a home.
                    'target' => 'Against the monthly cap',
                    'slice' => 5,
                ],
            ],
        ]);
    }

    /**
     * @return array{available: bool, pending: int|null, failed: int, supervisors: int|null}
     */
    private function queue(): array
    {
        $failed = DB::table('failed_jobs')->count();

        try {
            $jobs = app(JobRepository::class);
            $supervisors = app(MasterSupervisorRepository::class);

            return [
                'available' => true,
                'pending' => $jobs->countPending(),
                'failed' => $failed,
                'supervisors' => count($supervisors->all()),
            ];
        } catch (Throwable) {
            // Horizon needs Redis. A health screen that 500s when the thing it
            // monitors is down is the least useful possible health screen —
            // say the reporter is unreachable and still show the failed count,
            // which comes from the database.
            return [
                'available' => false,
                'pending' => null,
                'failed' => $failed,
                'supervisors' => null,
            ];
        }
    }
}
