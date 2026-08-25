<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Models\MessageTemplate;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageTemplateRequest extends FormRequest
{
    use MessageTemplateRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', MessageTemplate::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->messageTemplateRules();
    }
}
