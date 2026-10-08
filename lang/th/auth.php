<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication messages — Thai
|--------------------------------------------------------------------------
|
| Mirrors lang/en/auth.php. Read the notes there before changing any of these:
| the vagueness of `invalid_credentials` is deliberate and must survive
| translation, because a message that names the field that was wrong tells
| somebody guessing whether the account exists.
|
*/

return [

    'failure' => [
        'unexpected' => 'เกิดข้อผิดพลาดระหว่างการเข้าสู่ระบบ กรุณาลองอีกครั้ง',
        'unknown_server' => 'เซิร์ฟเวอร์เกมนั้นไม่พร้อมใช้งาน',
        'invalid_credentials' => 'ข้อมูลเข้าสู่ระบบไม่ถูกต้อง',
        'temporarily_banned' => 'บัญชีนี้ถูกระงับชั่วคราว กรุณาตรวจสอบอีเมลของคุณเพื่อดูเหตุผลและวันที่สิ้นสุด',
        'permanently_banned' => 'บัญชีนี้ถูกแบนถาวร',
        'ip_banned' => 'การเชื่อมต่อของคุณไม่ได้รับอนุญาตให้เข้าสู่ระบบ',
        'invalid_security_code' => 'รหัสความปลอดภัยไม่ถูกต้อง',
        'pending_confirmation' => 'บัญชีนี้ยังต้องได้รับการยืนยัน กรุณาตรวจสอบอีเมลของคุณเพื่อดูลิงก์ยืนยัน',
    ],

    'failed' => 'ข้อมูลเข้าสู่ระบบไม่ถูกต้อง',
    'password' => 'รหัสผ่านไม่ถูกต้อง',
    'throttle' => 'พยายามมากเกินไป กรุณาลองอีกครั้งใน :seconds วินาที',

];
