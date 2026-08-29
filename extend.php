<?php
/*
 * This file is part of redundans/PodcastFetcher.
 *
 * Copyright (c) 2026 redundans.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use redundans\PodcastFetcher\Console\FetchJsonCommand;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event;

return [
    // Registrera kommandot
    (new Extend\Console())
        ->command(FetchJsonCommand::class)
        ->schedule('noden:fetch-posts', function (Event $event) {
            $event->hourly();
        })
];
