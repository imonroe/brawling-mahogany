<?php

declare(strict_types=1);

namespace App\Http\Requests\Extraction;

use App\Enums\ExtractionKind;
use App\Models\Extraction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept several proposals at once — **inspection reports only** (#117).
 *
 * ## Why this request refuses a contract outright
 *
 * Screen Inventory over S66: it *"must make an unreviewed date impossible to
 * accept by accident, and **never default to 'confirm all'**."* #117 reasons
 * that the same control is defensible for tasks, because *"an unwanted task is
 * an annoyance, not a legal exposure"*.
 *
 * A rule stated only in a component is a rule the next caller lacks. So the
 * refusal is here, on the server, keyed on `extractions.kind` — and it means a
 * request crafted by hand cannot bulk-confirm a contract's dates any more than
 * the screen can offer to.
 *
 * ## And even here it is not "accept all"
 *
 * The ids are named explicitly. There is no *"everything pending"* form of this
 * endpoint, so accepting a batch still requires the client to have listed what
 * it is accepting — which is the difference between a reviewer choosing twelve
 * findings and a reviewer pressing one button over sixty.
 */
class AcceptExtractedFieldsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $extraction = $this->route('extraction');

        return $extraction instanceof Extraction
            && $extraction->kind === ExtractionKind::Inspection
            && ($this->user()?->can('confirm', $extraction) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Tick the tasks you want to keep.',
            'ids.min' => 'Tick the tasks you want to keep.',
        ];
    }

    /** @return list<string> */
    public function ids(): array
    {
        /** @var list<string> $ids */
        $ids = array_values(array_unique($this->validated()['ids']));

        return $ids;
    }
}
