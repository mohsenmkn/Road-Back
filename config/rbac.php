<?php

return [
    'guard' => 'web',

    'modules' => [
        // ماژول‌های موجود
        'dashboard' => ['view'],
        'auth' => ['manage', 'view_users'],
        'users' => ['read', 'create', 'update', 'delete'],
        'roles' => ['read', 'create', 'update', 'delete'],
        'permissions' => ['read', 'create', 'update', 'delete'],
        'groups' => ['read', 'create', 'update', 'delete', 'assign_users'],
        'road_expenses' => ['read', 'create', 'update', 'delete', 'manage_categories'],
        'road_manual_income' => ['create'],
        'road_indirect_cost' => ['manage'],


    ],

    'labels' => [
        'module_names' => array (
            'dashboard' => 'داشبورد',
            'auth' => 'احراز هویت',
            'permissions' => 'دسترسی ها',
            'users' => 'مدیریت کاربران',
            'roles' => 'نقش‌ها و دسترسی‌ها',
            'groups' => 'گروه‌های کاربری',
            'documents' => 'اسناد',
            'reports' => 'گزارش‌ها',
            'road_expenses'=> 'هزینه های رانندگان پیلسان',
            'road_manual_income' => 'ورود درآمد دستی حمل‌ونقل',
            'road_indirect_cost' => 'مدیریت هزینه‌های غیرمستقیم ناوگان',
        ),

        'actions' => array (
            'read' => 'مشاهده',
            'create' => 'ایجاد',
            'update' => 'ویرایش',
            'delete' => 'حذف',
            'view' => 'نمایش',
            'manage' => 'مدیریت کل',
            'view_users' => 'مشاهده کاربران',
            'assign_users' => 'اختصاص کاربران',
            'assign_representative' => 'تعیین نماینده',
            'submit_to_legal' => 'ارسال به حقوقی',
            'submit_to_contract_unit' => 'ارسال به واحد قراردادها',
            'review' => 'بررسی حقوقی',
            'draft' => 'تنظیم پیش‌نویس',
            'send_to_contractor' => 'ارسال به پیمانکار',
            'send_to_ceo' => 'ارسال به مدیرعامل',
            'notify' => 'ابلاغ',
            'sign' => 'امضا',
            'terminate' => 'فسخ',
            'submit' => 'ارسال برای تایید',
            'approve' => 'تایید',
            'release' => 'آزادسازی',
            'upload' => 'آپلود',
            'export' => 'خروجی',
        ),
    ],

    // تعریف نقش‌های پیش‌فرض و دسترسی‌های آن‌ها

];
