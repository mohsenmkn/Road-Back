<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleDisplayNameSeeder extends Seeder
{
    /**
     * Set Persian labels for the application's known system roles.
     * The role `name` values are intentionally never changed.
     */
    public function run(): void
    {
        $labels = [
            'super-admin' => 'مدیر کل سامانه',
            'admin' => 'مدیر سامانه',
            'viewer' => 'مشاهده‌گر',

        ];

        $updated = 0;
        $missing = [];

        foreach ($labels as $systemName => $displayName) {
            $roles = Role::query()->where('name', $systemName)->get();

            if ($roles->isEmpty()) {
                $missing[] = $systemName;
                continue;
            }

            foreach ($roles as $role) {
                $role->forceFill(['display_name' => $displayName])->save();
                $updated++;
            }
        }

        $this->command?->info("برچسب فارسی {$updated} نقش ثبت شد؛ نام‌های سیستمی بدون تغییر ماندند.");

        if ($missing !== []) {
            $this->command?->warn('نقش‌های موجود نبودند و رد شدند: ' . implode(', ', $missing));
        }
    }
}
