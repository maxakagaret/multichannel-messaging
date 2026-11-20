<?php

namespace App\Enums;

/**
 * How a campaign competes for a warming-up sender's daily limit.
 *
 * Without tiers the limit is handed out first come, first served: a bulk
 * campaign takes all 20 places of day one in half an hour, and the urgent
 * notification that comes next waits until tomorrow, when it is useless.
 */
enum Priority: string
{
    case Bulk = 'bulk';     // promotions, newsletters: may wait for tomorrow
    case Urgent = 'urgent'; // visit changes, service notices: take places ahead of bulk
}
