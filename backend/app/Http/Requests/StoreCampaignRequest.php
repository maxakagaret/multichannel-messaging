<?php

namespace App\Http\Requests;

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCampaignRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'channels' => ['required', 'array', 'min:1', 'max:4'],
            'channels.*.channel' => ['required', 'distinct', Rule::enum(Channel::class)],
            'channels.*.fallback' => ['sometimes', 'boolean'],
            'audience' => ['sometimes', 'array'],
            'audience.tags' => ['sometimes', 'array'],
            'audience.tags.*' => ['string', 'max:64'],
            'daily_cap' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'starts_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (($this->input('channels.0.fallback') ?? false) === true) {
                $validator->errors()->add('channels.0.fallback', 'The first channel cannot be a fallback.');
            }
        }];
    }

    public function payload(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'body' => $this->string('body')->toString(),
            'channels' => collect($this->input('channels'))
                ->map(fn (array $c) => ['channel' => $c['channel'], 'fallback' => (bool) ($c['fallback'] ?? false)])
                ->values()->all(),
            'audience' => ['tags' => array_values($this->input('audience.tags', []))],
            'daily_cap' => $this->integer('daily_cap', (int) config('messaging.daily_cap_default')),
            'starts_on' => $this->input('starts_on'),
            'status' => CampaignStatus::Draft,
        ];
    }
}
