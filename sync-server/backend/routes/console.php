<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('metr:prices:update')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('metr:subscriptions:renew')->dailyAt('03:00')->withoutOverlapping();
