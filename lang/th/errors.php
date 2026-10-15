<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HTTP error pages
|--------------------------------------------------------------------------
|
| Read by resources/views/errors/layout.blade.php, which every status view
| includes. `countdown` keeps its two forms as an array rather than using
| Laravel's pluralisation, because the sentence is rendered by the script on
| that page once a second and so has to reach the browser as a template.
|
*/

return [
    /* The two forms the countdown takes, substituted on :count. */
    'countdown' => [
        'one' => 'จะพาคุณกลับไปหน้าสถานะใน :count วินาที',
        'other' => 'จะพาคุณกลับไปหน้าสถานะใน :count วินาที',
    ],

    'stay' => 'อยู่ที่หน้านี้ต่อ',
    'stayed' => 'คุณจะอยู่ที่หน้านี้ต่อ',
    'back' => 'กลับไปหน้าสถานะ',

    'statuses' => [
        400 => [
            'title' => 'คำขอไม่ถูกต้อง',
            'body' => 'เซิร์ฟเวอร์ไม่เข้าใจคำขอนี้ ตรวจสอบที่อยู่แล้วลองใหม่อีกครั้ง',
        ],
        403 => [
            'title' => 'ไม่อนุญาตให้เข้าถึง',
            'body' => 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้',
        ],
        404 => [
            'title' => 'ไม่พบหน้านี้',
            'body' => 'ไม่มีหน้าที่คุณกำลังมองหา',
        ],
        408 => [
            'title' => 'คำขอหมดเวลา',
            'body' => 'คำขอนี้ใช้เวลานานเกินไปจึงถูกยกเลิก ลองใหม่อีกครั้ง',
        ],
        429 => [
            'title' => 'คำขอมากเกินไป',
            'body' => 'มีคำขอมากเกินไปในเวลาอันสั้น รอสักครู่แล้วลองใหม่อีกครั้ง',
        ],
    ],
];
