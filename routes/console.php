<?php

use App\Jobs\ReconcilePendingGenerations;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ReconcilePendingGenerations)->everyMinute()->withoutOverlapping();
