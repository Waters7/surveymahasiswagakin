<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\SurveyNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DeliverySchedule
{
    public const KEY = 'delivery_schedule';

    private function record(): SystemSetting
    {
        return SystemSetting::firstOrCreate(['key' => self::KEY], ['value' => [
            'enabled' => true, 'time' => '16:00', 'timezone' => 'Asia/Makassar',
            'configured_at' => now()->toIso8601String(), 'last_due_at' => null, 'runs' => [],
        ]]);
    }

    /** @return array{enabled: bool, time: string, timezone: string, configured_at: string, last_due_at: ?string, runs: array} */
    public function settings(): array
    {
        return $this->record()->value;
    }

    public function save(bool $enabled, string $time): void
    {
        $record = $this->record();
        DB::transaction(function () use ($record, $enabled, $time): void {
            $record = SystemSetting::lockForUpdate()->findOrFail($record->id);
            $before = $record->value;
            $settings = array_replace($before, ['enabled' => $enabled, 'time' => $time, 'configured_at' => now()->toIso8601String()]);
            $record->update(['value' => $settings]);
            Audit::record('delivery.schedule_updated', $record, ['enabled' => $before['enabled'], 'time' => $before['time']], ['enabled' => $enabled, 'time' => $time]);
        });
    }

    public function nextRun(): ?CarbonImmutable
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return null;
        }
        $now = CarbonImmutable::now('Asia/Makassar');
        $next = $now->setTimeFromTimeString($settings['time'])->startOfMinute();

        return $next->lessThanOrEqualTo($now) ? $next->addDay() : $next;
    }

    public function remindDue(): bool
    {
        $record = $this->record();

        return DB::transaction(function () use ($record): bool {
            $record = SystemSetting::lockForUpdate()->findOrFail($record->id);
            $settings = $record->value;
            $now = CarbonImmutable::now('Asia/Makassar');
            $due = $now->setTimeFromTimeString($settings['time'])->startOfMinute();
            $lastDue = $settings['last_due_at'] ? CarbonImmutable::parse($settings['last_due_at']) : null;
            if (! $settings['enabled'] || $now->lessThan($due) || $due->lessThan(CarbonImmutable::parse($settings['configured_at'])) || $lastDue?->isSameDay($due)) {
                return false;
            }
            $message = 'Jadwal pengiriman data '.$due->format('d-m-Y H:i').' WITA telah tiba. Pengiriman belum dilakukan; integrasi menunggu aktivasi dan pemeriksaan admin.';
            foreach (User::permission('masters.manage')->where('active', true)->get() as $admin) {
                $admin->notify(new SurveyNotice($message, '/pangi'));
            }
            $settings['last_due_at'] = $due->toIso8601String();
            array_unshift($settings['runs'], ['scheduled_at' => $due->toIso8601String(), 'recorded_at' => $now->toIso8601String(), 'status' => 'Menunggu integrasi']);
            $settings['runs'] = array_slice($settings['runs'], 0, 30);
            $record->update(['value' => $settings]);
            Audit::record('delivery.reminder_created', $record, [], ['scheduled_at' => $due->toIso8601String(), 'sent_externally' => false]);

            return true;
        });
    }
}
