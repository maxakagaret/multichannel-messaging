<?php

namespace App\Messaging\Rendering;

use App\Enums\Channel;
use App\Models\Contact;

/**
 * Renders a campaign template for one contact and one channel.
 *
 * Templates use {{variables}} and a single formatting primitive, **bold**,
 * which every channel spells differently. Variables are substituted after
 * formatting and escaped for HTML channels, so a contact name can never
 * inject markup.
 */
class MessageRenderer
{
    public function render(string $template, Contact $contact, Channel $channel): string
    {
        $formatted = $this->format($template, $channel);

        $variables = [
            'name' => $contact->name,
            'first_name' => strtok($contact->name, ' ') ?: $contact->name,
        ];

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function (array $m) use ($variables, $channel) {
            $value = $variables[$m[1]] ?? '';

            return $this->isHtml($channel) ? e($value) : $value;
        }, $formatted);
    }

    private function format(string $template, Channel $channel): string
    {
        $body = $this->isHtml($channel) ? e($template) : $template;

        $replacement = match ($channel) {
            Channel::WhatsApp => '*$1*',
            Channel::Telegram => '<b>$1</b>',
            Channel::Email => '<strong>$1</strong>',
            Channel::Sms => '$1',
        };

        $body = preg_replace('/\*\*(.+?)\*\*/s', $replacement, $body);

        return $channel === Channel::Email ? nl2br($body, false) : $body;
    }

    private function isHtml(Channel $channel): bool
    {
        return in_array($channel, [Channel::Email, Channel::Telegram], true);
    }
}
