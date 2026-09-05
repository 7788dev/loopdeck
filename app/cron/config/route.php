<?php

declare(strict_types=1);

return [
    // Common controller helpers are not HTTP actions. Only declared task
    // endpoints may be dispatched, even when a caller knows the cron key.
    'url_route_must' => true,
    'route_complete_match' => true,
];
