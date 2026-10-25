<?php

use Illuminate\Support\Facades\Schedule;

// Runs the ledger checks every night. A failure exits non-zero and logs at critical level.
Schedule::command('wallet:reconcile')->dailyAt('02:00')->withoutOverlapping();
