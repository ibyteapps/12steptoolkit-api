<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Who did what, in the console.
 *
 * `subject_type`/`subject_id` name the thing, never its contents. The console is
 * built so that a staff member can help somebody without reading a word of what
 * they wrote, and an audit log that quoted the thing it was auditing would be
 * the hole in that.
 *
 * Reads are recorded as well as writes. Opening somebody's account page is the
 * action worth being able to look back on — a change leaves its own trace in the
 * thing it changed, but a look does not.
 */
class ConsoleAudit extends Model
{
    protected $table = 'console_audit';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    public function consoleUser(): BelongsTo
    {
        return $this->belongsTo(ConsoleUser::class, 'console_user_id');
    }

    /**
     * Record an action.
     *
     * Never throws: an audit write that fails must not be the reason a support
     * person cannot answer a ticket. It is logged instead, which is the one place
     * a missing audit row becomes visible.
     */
    public static function record(string $action, ?Model $subject = null, array $context = []): void
    {
        try {
            self::query()->insert([
                'console_user_id' => auth('console')->id(),
                'action' => $action,
                'subject_type' => $subject === null ? null : class_basename($subject),
                'subject_id' => $subject === null ? null : (string) $subject->getKey(),
                'context' => $context === [] ? null : json_encode($context),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('console audit write failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
