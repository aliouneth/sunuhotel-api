<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Tenancy\HotelContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Central helper for critical-action audit logging. Call log reads/writes as
 * early as possible so the actor context is still attached.
 */
final class AuditLogger
{
    public static function log(
        string $action,
        Model|string|null $entity = null,
        int|null $entityId = null,
        array $context = [],
    ): AuditLog {
        /** @var \App\Models\User|null $user */
        $user = auth('sanctum')->user() ?? auth()->user();
        $request = request();

        $paylod = [
            'hotel_id' => $entity instanceof Model && isset($entity->hotel_id)
                ? $entity->hotel_id
                : HotelContext::id(),
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => is_string($entity) ? $entity : ($entity ? get_class($entity) : null),
            'entity_id' => $entity instanceof Model ? $entity->getKey() : $entityId,
            'context' => empty($context) ? null : $context,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ];

        return AuditLog::query()->create($paylod);
    }

    public static function critical(Model $entity, string $action, array $context = []): AuditLog
    {
        return self::log($action, $entity, null, $context);
    }
}