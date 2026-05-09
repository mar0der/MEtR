<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('metr:prices:update')->dailyAt('04:10')->withoutOverlapping();
