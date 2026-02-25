<?php

// during automated tests we don't need broadcasting and the default "reverb"
// driver may not be configured, which would cause an exception when the file
// is loaded.  Guard the entire file so tests can boot without errors.
if (app()->environment('testing')) {
    return;
}

// if the reverb broadcaster isn't configured (which is common during tests
// or on developer machines with missing environment variables) we still
// attempt to register the channel, but we guard the actual call in a
// try/catch to prevent a blown-up application.

use Illuminate\Support\Facades\Broadcast;

try {
    Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
        return (int) $user->id === (int) $id;
    });
} catch (\Throwable $e) {
    // ignore; broadcasting simply isn't set up
}
