<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $documentType = null,
        ?int $documentId = null
    ): Notification {
        return Notification::notify($userId, $type, $title, $body, $documentType, $documentId);
    }

    public function notifyUsersWithRole(
        string $roleCode,
        string $type,
        string $title,
        ?string $body = null,
        ?string $documentType = null,
        ?int $documentId = null
    ): void {
        $users = User::whereHas('roles', fn($q) => $q->where('code', $roleCode))
            ->where('is_active', true)
            ->get();

        foreach ($users as $user) {
            $this->notify($user->id, $type, $title, $body, $documentType, $documentId);
        }
    }

    public function markAsRead(string $notificationId): bool
    {
        $notification = Notification::find($notificationId);
        if ($notification) {
            $notification->markAsRead();
            return true;
        }
        return false;
    }

    public function markAllAsRead(int $userId): int
    {
        return Notification::forUser($userId)->unread()->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function getUnreadCount(int $userId): int
    {
        return Notification::forUser($userId)->unread()->count();
    }

    public function getUserNotifications(int $userId, int $perPage = 20)
    {
        return Notification::forUser($userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}