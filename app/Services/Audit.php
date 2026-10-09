<?php

namespace App\Services;

use App\Models\AuditLog;

class Audit
{
    public static function record(string $action, $subject, array $before = [], array $after = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => is_object($subject) ? class_basename($subject) : 'System', 'subject_id' => is_object($subject) ? (string) $subject->getKey() : (string) $subject, 'before' => $before, 'after' => $after, 'ip' => request()->ip(), 'user_agent' => mb_substr(request()->userAgent() ?? '', 0, 1000)]);
    }
}
