<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('organizations.{organizationId}.stations', function ($user, $organizationId) {
    return $user->status === 'active'
        && ($user->hasRole('super_admin') || (int) $user->organization_id === (int) $organizationId);
});

Broadcast::channel('stations.public', function ($user) {
    return $user->status === 'active' && $user->hasRole('client');
});

Broadcast::channel('stations.super-admin', function ($user) {
    return $user->status === 'active' && $user->hasRole('super_admin');
});

Broadcast::channel('organizations.{organizationId}.sessions', function ($user, $organizationId) {
    return $user->status === 'active'
        && ($user->hasRole('super_admin') || (int) $user->organization_id === (int) $organizationId);
});

Broadcast::channel('users.{userId}.sessions', function ($user, $userId) {
    return $user->status === 'active' && (int) $user->id === (int) $userId;
});

Broadcast::channel('users.{userId}.notifications', function ($user, $userId) {
    return $user->status === 'active' && (int) $user->id === (int) $userId;
});

Broadcast::channel('sessions.super-admin', function ($user) {
    return $user->status === 'active' && $user->hasRole('super_admin');
});
