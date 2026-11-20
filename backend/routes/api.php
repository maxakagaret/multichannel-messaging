<?php

use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\SenderController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::get('stats/overview', [StatsController::class, 'overview']);

Route::get('contacts', [ContactController::class, 'index']);
Route::get('contacts/tags', [ContactController::class, 'tags']);

Route::get('senders', [SenderController::class, 'index']);
Route::patch('senders/{sender}', [SenderController::class, 'update']);
Route::post('senders/{sender}/link-token', [SenderController::class, 'issueLinkToken']);
Route::delete('senders/{sender}/link', [SenderController::class, 'unlink']);
// Called by the tool holding the account credentials; the token is the authorization.
Route::post('senders/link/{token}', [SenderController::class, 'redeemLinkToken'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:10,1');

Route::get('campaigns', [CampaignController::class, 'index']);
Route::post('campaigns', [CampaignController::class, 'store']);
Route::get('campaigns/{campaign}', [CampaignController::class, 'show']);
Route::post('campaigns/{campaign}/launch', [CampaignController::class, 'launch']);
Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);
Route::get('campaigns/{campaign}/stats', [CampaignController::class, 'stats']);
Route::get('campaigns/{campaign}/messages', [CampaignController::class, 'messages']);

Route::get('messages/{message}', [MessageController::class, 'show']);

Route::post('webhooks/{channel}/receipts', [WebhookController::class, 'receipts'])
    ->middleware(VerifyWebhookSignature::class);
