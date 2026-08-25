<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Models\MessageTemplate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageTemplateRequest extends FormRequest
{
    use MessageTemplateRules;

    public function authorize(): bool
    {
        $template = $this->route('messageTemplate');

        return $template instanceof MessageTemplate
            && ($this->user()?->can('update', $template) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Ignoring its own row, or a template could never be saved twice —
        // and changing only its body would be refused for colliding with
        // itself.
        $template = $this->route('messageTemplate');

        return $this->messageTemplateRules($template instanceof MessageTemplate ? $template : null);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->messageTemplateMessages();
    }
}
