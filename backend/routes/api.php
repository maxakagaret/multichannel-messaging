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
