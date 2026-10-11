<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
