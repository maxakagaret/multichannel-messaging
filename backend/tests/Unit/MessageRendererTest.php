<?php

use App\Enums\Channel;
use App\Messaging\Rendering\MessageRenderer;
use App\Models\Contact;

beforeEach(function () {
    $this->renderer = new MessageRenderer;
    $this->contact = new Contact(['name' => 'Ada Lovelace']);
});

it('renders variables and channel-specific bold', function (Channel $channel, string $expected) {
    expect($this->renderer->render('Hi {{first_name}}, **20% off**', $this->contact, $channel))->toBe($expected);
})->with([
    'whatsapp' => [Channel::WhatsApp, 'Hi Ada, *20% off*'],
    'telegram' => [Channel::Telegram, 'Hi Ada, <b>20% off</b>'],
    'email' => [Channel::Email, 'Hi Ada, <strong>20% off</strong>'],
    'sms' => [Channel::Sms, 'Hi Ada, 20% off'],
]);

it('escapes contact data in HTML channels', function () {
    $contact = new Contact(['name' => '<script>alert(1)</script>']);

    expect($this->renderer->render('Hello {{name}}', $contact, Channel::Email))
        ->toBe('Hello &lt;script&gt;alert(1)&lt;/script&gt;');
});

it('escapes template markup in HTML channels but keeps line breaks', function () {
    expect($this->renderer->render("<i>x</i>\nline", $this->contact, Channel::Email))
        ->toBe("&lt;i&gt;x&lt;/i&gt;<br>\nline");
});

it('leaves unknown variables empty', function () {
    expect($this->renderer->render('A{{unknown}}B', $this->contact, Channel::Sms))->toBe('AB');
});
